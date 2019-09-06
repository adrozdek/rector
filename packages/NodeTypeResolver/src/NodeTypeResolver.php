<?php declare(strict_types=1);

namespace Rector\NodeTypeResolver;

use Countable;
use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\NodeTraverser;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\Broker;
use PHPStan\Type\Accessory\HasOffsetType;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeWithClassName;
use PHPStan\Type\UnionType;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Contract\NodeTypeResolverAwareInterface;
use Rector\NodeTypeResolver\Contract\PerNodeTypeResolver\PerNodeTypeResolverInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\Type\TypeFactory;
use Rector\NodeTypeResolver\Reflection\ClassReflectionTypesResolver;
use Rector\PhpParser\Node\Resolver\NameResolver;
use Rector\PhpParser\NodeTraverser\CallableNodeTraverser;
use Rector\PhpParser\Printer\BetterStandardPrinter;

final class NodeTypeResolver
{
    /**
     * @var PerNodeTypeResolverInterface[]
     */
    private $perNodeTypeResolvers = [];

    /**
     * @var NameResolver
     */
    private $nameResolver;

    /**
     * @var BetterStandardPrinter
     */
    private $betterStandardPrinter;

    /**
     * @var CallableNodeTraverser
     */
    private $callableNodeTraverser;

    /**
     * @var ClassReflectionTypesResolver
     */
    private $classReflectionTypesResolver;

    /**
     * @var Broker
     */
    private $broker;

    /**
     * @var TypeFactory
     */
    private $typeFactory;

    /**
     * @param PerNodeTypeResolverInterface[] $perNodeTypeResolvers
     */
    public function __construct(
        BetterStandardPrinter $betterStandardPrinter,
        NameResolver $nameResolver,
        CallableNodeTraverser $callableNodeTraverser,
        ClassReflectionTypesResolver $classReflectionTypesResolver,
        Broker $broker,
        TypeFactory $typeFactory,
        array $perNodeTypeResolvers
    ) {
        $this->betterStandardPrinter = $betterStandardPrinter;
        $this->nameResolver = $nameResolver;

        foreach ($perNodeTypeResolvers as $perNodeTypeResolver) {
            $this->addPerNodeTypeResolver($perNodeTypeResolver);
        }

        $this->callableNodeTraverser = $callableNodeTraverser;
        $this->classReflectionTypesResolver = $classReflectionTypesResolver;
        $this->broker = $broker;
        $this->typeFactory = $typeFactory;
    }

    /**
     * @param ObjectType|string $requiredType
     */
    public function isObjectType(Node $node, $requiredType): bool
    {
        if (is_string($requiredType)) {
            if (Strings::contains($requiredType, '*')) {
                return $this->isFnMatch($node, $requiredType);
            }
        }

        $resolvedType = $this->getObjectType($node);

        if ($resolvedType instanceof MixedType) {
            return false;
        }

        // this should also work with ObjectType and UnionType with ObjectType
        // use PHPStan types here

        if (is_string($requiredType)) {
            $requiredType = new ObjectType($requiredType);
        }

        if ($resolvedType->equals($requiredType)) {
            return true;
        }

        if ($resolvedType instanceof ObjectType) {
            if (is_a($resolvedType->getClassName(), $requiredType->getClassName(), true)) {
                return true;
            }
        }

        if ($this->isUnionNullTypeOfRequiredType($requiredType, $resolvedType)) {
            return true;
        }

        return $resolvedType->isSuperTypeOf($requiredType)->yes();
    }

    public function getObjectType(Node $node): Type
    {
        // @todo should be resolved by NodeTypeResolver internally
        if ($node instanceof MethodCall || $node instanceof ArrayDimFetch) {
            return $this->resolve($node->var);
        }

        return $this->resolve($node);
    }

    public function resolve(Node $node): Type
    {
        if ($node instanceof ClassMethod || $node instanceof ClassConst) {
            return $this->resolveClassNode($node);
        }

        if ($node instanceof StaticCall) {
            return $this->resolveStaticCallType($node);
        }

        if ($node instanceof ClassConstFetch) {
            return $this->resolve($node->class);
        }

        if ($node instanceof Cast) {
            return $this->resolve($node->expr);
        }

        $type = $this->resolveFirstType($node);
        if (! $type instanceof TypeWithClassName) {
            return $type;
        }

        return $this->unionWithParentClassesInterfacesAndUsedTraits($type);
    }

    public function isStringOrUnionStringOnlyType(Node $node): bool
    {
        $nodeType = $this->getStaticType($node);
        if ($nodeType instanceof StringType) {
            return true;
        }

        if ($nodeType instanceof UnionType) {
            foreach ($nodeType->getTypes() as $singleType) {
                if (! $singleType instanceof StringType) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * e.g. string|null, ObjectNull|null
     */
    public function isNullableType(Node $node): bool
    {
        $nodeType = $this->getStaticType($node);
        if (! $nodeType instanceof UnionType) {
            return false;
        }

        return $nodeType->isSuperTypeOf(new NullType())->yes();
    }

    public function isCountableType(Node $node): bool
    {
        $nodeType = $this->getStaticType($node);

        $nodeType = $this->correctPregMatchType($node, $nodeType);
        if ($nodeType instanceof ObjectType) {
            return is_a($nodeType->getClassName(), Countable::class, true);
        }

        return $this->isArrayType($node);
    }

    public function isArrayType(Node $node): bool
    {
        $nodeStaticType = $this->getStaticType($node);

        $nodeStaticType = $this->correctPregMatchType($node, $nodeStaticType);
        if ($this->isIntersectionArrayType($nodeStaticType)) {
            return true;
        }

        if ($node instanceof PropertyFetch) {
            // PHPStan false positive, when variable has type[] docblock, but default array is missing
            if ($this->isPropertyFetchWithArrayDefault($node) === false) {
                return false;
            }
        }

        if ($nodeStaticType instanceof MixedType) {
            if ($nodeStaticType->isExplicitMixed()) {
                return false;
            }

            if ($this->isPropertyFetchWithArrayDefault($node)) {
                return true;
            }
        }

        return $nodeStaticType instanceof ArrayType;
    }

    public function getStaticType(Node $node): Type
    {
        if ($node instanceof String_) {
            return new ConstantStringType($node->value);
        }

        if ($node instanceof Param) {
            $paramStaticType = $this->resolveParamStaticType($node);
            if ($paramStaticType !== null) {
                return $paramStaticType;
            }
        }

        /** @var Scope|null $nodeScope */
        $nodeScope = $node->getAttribute(AttributeKey::SCOPE);
        if (! $node instanceof Expr || $nodeScope === null) {
            return new MixedType();
        }

        if ($node instanceof New_) {
            if ($this->isAnonymousClass($node->class)) {
                return new ObjectWithoutClassType();
            }
        }

        return $nodeScope->getType($node);
    }

    public function resolveNodeToPHPStanType(Expr $expr): Type
    {
        if ($this->isArrayType($expr)) {
            $arrayType = $this->getStaticType($expr);

            if ($arrayType instanceof ArrayType) {
                return $arrayType;
            }

            return new ArrayType(new MixedType(), new MixedType());
        }

        if ($this->isStringOrUnionStringOnlyType($expr)) {
            return new StringType();
        }

        return $this->getStaticType($expr);
    }

    public function isNullableObjectType(Node $node): bool
    {
        $nodeType = $this->getStaticType($node);
        if (! $nodeType instanceof UnionType) {
            return false;
        }

        if ($nodeType->isSuperTypeOf(new NullType())->no()) {
            return false;
        }

        if (count($nodeType->getTypes()) !== 2) {
            return false;
        }

        foreach ($nodeType->getTypes() as $subtype) {
            if ($subtype instanceof ObjectType) {
                return true;
            }
        }

        return false;
    }

    public function isNumberType(Node $node): bool
    {
        return $this->isStaticType($node, IntegerType::class) || $this->isStaticType($node, FloatType::class);
    }

    public function isStaticType(Node $node, string $staticTypeClass): bool
    {
        if (! is_a($staticTypeClass, Type::class, true)) {
            throw new ShouldNotHappenException(sprintf(
                '"%s" in "%s()" must be type of "%s"',
                $staticTypeClass,
                __METHOD__,
                Type::class
            ));
        }

        return is_a($this->getStaticType($node), $staticTypeClass);
    }

    private function addPerNodeTypeResolver(PerNodeTypeResolverInterface $perNodeTypeResolver): void
    {
        foreach ($perNodeTypeResolver->getNodeClasses() as $nodeClass) {
            $this->perNodeTypeResolvers[$nodeClass] = $perNodeTypeResolver;
        }

        // in-code setter injection to drop CompilerPass requirement for 3rd party package install
        if ($perNodeTypeResolver instanceof NodeTypeResolverAwareInterface) {
            $perNodeTypeResolver->setNodeTypeResolver($this);
        }
    }

    /**
     * @param ClassConst|ClassMethod $node
     */
    private function resolveClassNode(Node $node): Type
    {
        $classNode = $node->getAttribute(AttributeKey::CLASS_NODE);
        if ($classNode === null) {
            // anonymous class
            return new ObjectWithoutClassType();
        }

        return $this->resolve($classNode);
    }

    private function resolveFirstType(Node $node): Type
    {
        // nodes that cannot be resolver by PHPStan
        $nodeClass = get_class($node);

        if (isset($this->perNodeTypeResolvers[$nodeClass])) {
            return $this->perNodeTypeResolvers[$nodeClass]->resolve($node);
        }

        /** @var Scope|null $nodeScope */
        $nodeScope = $node->getAttribute(AttributeKey::SCOPE);

        if ($nodeScope === null) {
            return new MixedType();
        }

        if (! $node instanceof Expr) {
            return new MixedType();
        }

        // skip anonymous classes, ref https://github.com/rectorphp/rector/issues/1574
        if ($node instanceof New_) {
            if ($this->isAnonymousClass($node->class)) {
                return new ObjectWithoutClassType();
            }
        }

        $type = $nodeScope->getType($node);

        // hot fix for phpstan not resolving chain method calls
        if ($node instanceof MethodCall && $type instanceof MixedType) {
            return $this->resolveFirstType($node->var);
        }

        return $type;
    }

    private function resolveStaticCallType(StaticCall $staticCall): Type
    {
        $classType = $this->resolve($staticCall->class);
        $methodName = $this->nameResolver->getName($staticCall->name);

        // no specific method found, return class types, e.g. <ClassType>::$method()
        if (! is_string($methodName)) {
            return $classType;
        }

        $classTypes = [];
        if ($classType instanceof ObjectType) {
            $classTypes[] = $classType;
        } elseif ($classType instanceof UnionType) {
            foreach ($classType->getTypes() as $unionedType) {
                if ($unionedType instanceof ObjectType) {
                    $classTypes[] = $unionedType;
                }
            }
        }

        foreach ($classTypes as $classType) {
            if (! method_exists($classType->getClassName(), $methodName)) {
                continue;
            }

            /** @var Scope|null $nodeScope */
            $nodeScope = $staticCall->getAttribute(AttributeKey::SCOPE);
            if ($nodeScope === null) {
                return $classType;
            }

            return $nodeScope->getType($staticCall);
        }

        return $classType;
    }

    /**
     * Special case for "preg_match(), preg_match_all()" - with 3rd argument
     * @covers https://github.com/rectorphp/rector/issues/786
     */
    private function correctPregMatchType(Node $node, Type $originalType): Type
    {
        /** @var Expression|null $previousExpression */
        $previousExpression = $node->getAttribute(AttributeKey::CURRENT_EXPRESSION);
        if ($previousExpression === null) {
            return $originalType;
        }

        if (! $previousExpression->expr instanceof FuncCall) {
            return $originalType;
        }

        $funcCallNode = $previousExpression->expr;
        if (! $this->nameResolver->isNames($funcCallNode, ['preg_match', 'preg_match_all'])) {
            return $originalType;
        }

        if (! isset($funcCallNode->args[2])) {
            return $originalType;
        }

        // are the same variables
        if (! $this->betterStandardPrinter->areNodesEqual($funcCallNode->args[2]->value, $node)) {
            return $originalType;
        }

        if ($originalType instanceof ArrayType) {
            return $originalType;
        }

        return new ArrayType(new MixedType(), new MixedType());
    }

    private function isIntersectionArrayType(Type $nodeType): bool
    {
        if (! $nodeType instanceof IntersectionType) {
            return false;
        }

        foreach ($nodeType->getTypes() as $intersectionNodeType) {
            if ($intersectionNodeType instanceof ArrayType || $intersectionNodeType instanceof HasOffsetType || $intersectionNodeType instanceof NonEmptyArrayType) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * phpstan bug workaround - https://phpstan.org/r/0443f283-244c-42b8-8373-85e7deb3504c
     */
    private function isPropertyFetchWithArrayDefault(Node $node): bool
    {
        if (! $node instanceof PropertyFetch) {
            return false;
        }

        /** @var Class_ $classNode */
        $classNode = $node->getAttribute(AttributeKey::CLASS_NODE);

        $propertyName = $this->nameResolver->getName($node->name);
        if ($propertyName === null) {
            return false;
        }

        $propertyPropertyNode = $this->getClassNodeProperty($classNode, $propertyName);
        if ($propertyPropertyNode === null) {
            return false;
        }

        return $propertyPropertyNode->default instanceof Array_;
    }

    private function getClassNodeProperty(Class_ $class, string $name): ?PropertyProperty
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $propertyProperty) {
                if ($this->nameResolver->isName($propertyProperty, $name)) {
                    return $propertyProperty;
                }
            }
        }

        return null;
    }

    private function isAnonymousClass(Node $node): bool
    {
        if (! $node instanceof Class_) {
            return false;
        }

        $className = $this->nameResolver->getName($node);

        return $className === null || Strings::contains($className, 'AnonymousClass');
    }

    private function resolveParamStaticType(Node $node): Type
    {
        $classMethod = $node->getAttribute(AttributeKey::METHOD_NODE);
        if ($classMethod === null) {
            return new MixedType();
        }

        /** @var string $paramName */
        $paramName = $this->nameResolver->getName($node);
        $paramStaticType = new MixedType();

        // special case for param inside method/function
        $this->callableNodeTraverser->traverseNodesWithCallable(
            (array) $classMethod->stmts,
            function (Node $node) use ($paramName, &$paramStaticType): ?int {
                if (! $node instanceof Variable) {
                    return null;
                }

                if (! $this->nameResolver->isName($node, $paramName)) {
                    return null;
                }

                $paramStaticType = $this->getStaticType($node);

                return NodeTraverser::STOP_TRAVERSAL;
            }
        );

        return $paramStaticType;
    }

    private function isUnionNullTypeOfRequiredType(ObjectType $objectType, Type $resolvedType): bool
    {
        if (! $resolvedType instanceof UnionType) {
            return false;
        }

        if (count($resolvedType->getTypes()) !== 2) {
            return false;
        }

        $firstType = $resolvedType->getTypes()[0];
        $secondType = $resolvedType->getTypes()[1];

        if ($firstType instanceof NullType && $secondType instanceof ObjectType) {
            $resolvedType = $secondType;
        } elseif ($secondType instanceof NullType && $firstType instanceof ObjectType) {
            $resolvedType = $firstType;
        } else {
            return false;
        }

        return is_a($resolvedType->getClassName(), $objectType->getClassName(), true);
    }

    private function isFnMatch(Node $node, string $requiredType): bool
    {
        $resolvedType = $this->getObjectType($node);

        if ($resolvedType instanceof TypeWithClassName) {
            return $this->isObjectTypeFnMatch($resolvedType, $requiredType);
        }

        if ($resolvedType instanceof UnionType) {
            foreach ($resolvedType->getTypes() as $unionedType) {
                if (! $unionedType instanceof TypeWithClassName) {
                    continue;
                }

                if ($this->isObjectTypeFnMatch($unionedType, $requiredType)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isObjectTypeFnMatch(TypeWithClassName $typeWithClassName, string $requiredType): bool
    {
        return fnmatch($requiredType, $typeWithClassName->getClassName(), FNM_NOESCAPE);
    }

    private function unionWithParentClassesInterfacesAndUsedTraits(Type $type): Type
    {
        if ($type instanceof TypeWithClassName) {
            if (! $this->classLikeTypeExists($type)) {
                return $type;
            }

            $classReflection = $this->broker->getClass($type->getClassName());

            $allTypes = $this->classReflectionTypesResolver->resolve($classReflection);
            $allTypes = array_unique($allTypes);

            return $this->typeFactory->createObjectTypeOrUnionType($allTypes);
        }

        if ($type instanceof UnionType) {
            $allTypes = [];

            foreach ($type->getTypes() as $unionedType) {
                if ($unionedType instanceof TypeWithClassName) {
                    if (! $this->classLikeTypeExists($unionedType)) {
                        continue;
                    }

                    $classReflection = $this->broker->getClass($unionedType->getClassName());

                    $allTypes = array_merge(
                        $allTypes,
                        $this->classReflectionTypesResolver->resolve($classReflection)
                    );
                }
            }

            return $this->typeFactory->createObjectTypeOrUnionType($allTypes);
        }

        return $type;
    }

    private function classLikeTypeExists(TypeWithClassName $typeWithClassName): bool
    {
        if (class_exists($typeWithClassName->getClassName())) {
            return true;
        }

        if (interface_exists($typeWithClassName->getClassName())) {
            return true;
        }

        return trait_exists($typeWithClassName->getClassName());
    }
}
