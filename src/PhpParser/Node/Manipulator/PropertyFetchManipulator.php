<?php declare(strict_types=1);

namespace Rector\PhpParser\Node\Manipulator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Broker\Broker;
use PHPStan\Type\ErrorType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\PhpParser\Node\Resolver\NameResolver;
use Rector\PhpParser\NodeTraverser\CallableNodeTraverser;

/**
 * Read-only utils for PropertyFetch Node:
 * "$this->property"
 */
final class PropertyFetchManipulator
{
    /**
     * @var NodeTypeResolver
     */
    private $nodeTypeResolver;

    /**
     * @var Broker
     */
    private $broker;

    /**
     * @var NameResolver
     */
    private $nameResolver;

    /**
     * @var ClassManipulator
     */
    private $classManipulator;

    /**
     * @var CallableNodeTraverser
     */
    private $callableNodeTraverser;

    public function __construct(
        NodeTypeResolver $nodeTypeResolver,
        Broker $broker,
        NameResolver $nameResolver,
        ClassManipulator $classManipulator,
        CallableNodeTraverser $callableNodeTraverser
    ) {
        $this->nodeTypeResolver = $nodeTypeResolver;
        $this->broker = $broker;
        $this->nameResolver = $nameResolver;
        $this->classManipulator = $classManipulator;
        $this->callableNodeTraverser = $callableNodeTraverser;
    }

    public function isPropertyToSelf(PropertyFetch $propertyFetch): bool
    {
        if (! $this->nameResolver->isName($propertyFetch->var, 'this')) {
            return false;
        }

        /** @var Class_|null $class */
        $class = $propertyFetch->getAttribute(AttributeKey::CLASS_NODE);
        if ($class === null) {
            return false;
        }

        return $this->classManipulator->hasPropertyFetchAsProperty($class, $propertyFetch);
    }

    public function isMagicOnType(Node $node, Type $type): bool
    {
        if (! $node instanceof PropertyFetch) {
            return false;
        }

        $varNodeType = $this->nodeTypeResolver->resolve($node);

        if ($varNodeType instanceof ErrorType) {
            return true;
        }

        if ($varNodeType instanceof MixedType) {
            return false;
        }

        if ($varNodeType->isSuperTypeOf($type)) {
            return false;
        }

        $nodeName = $this->nameResolver->getName($node);
        if ($nodeName === null) {
            return false;
        }

        return ! $this->hasPublicProperty($node, $nodeName);
    }

    /**
     * @return string[]
     */
    public function getPropertyNamesOfAssignOfVariable(Node $node, string $paramName): array
    {
        $propertyNames = [];

        $this->callableNodeTraverser->traverseNodesWithCallable($node, function (Node $node) use (
            $paramName,
            &$propertyNames
        ) {
            if (! $this->isVariableAssignToThisPropertyFetch($node, $paramName)) {
                return null;
            }

            /** @var Assign $node */
            $propertyName = $this->nameResolver->getName($node->expr);
            if ($propertyName) {
                $propertyNames[] = $propertyName;
            }

            return null;
        });

        return $propertyNames;
    }

    /**
     * Matches:
     * "$this->someValue = $<variableName>;"
     */
    public function isVariableAssignToThisPropertyFetch(Node $node, string $variableName): bool
    {
        if (! $node instanceof Assign) {
            return false;
        }

        if (! $node->expr instanceof Variable) {
            return false;
        }

        if (! $this->nameResolver->isName($node->expr, $variableName)) {
            return false;
        }

        if (! $node->var instanceof PropertyFetch) {
            return false;
        }

        // must be local property
        if (! $this->nameResolver->isName($node->var->var, 'this')) {
            return false;
        }

        return true;
    }

    /**
     * @param string[] $propertyNames
     */
    public function isLocalPropertyOfNames(Expr $expr, array $propertyNames): bool
    {
        if (! $expr instanceof PropertyFetch) {
            return false;
        }

        if (! $this->isLocalProperty($expr)) {
            return false;
        }

        return $this->nameResolver->isNames($expr->name, $propertyNames);
    }

    public function isLocalProperty(Node $node): bool
    {
        if (! $node instanceof PropertyFetch) {
            return false;
        }

        return $this->nameResolver->isName($node->var, 'this');
    }

    private function hasPublicProperty(PropertyFetch $propertyFetch, string $propertyName): bool
    {
        $nodeScope = $propertyFetch->getAttribute(AttributeKey::SCOPE);
        if ($nodeScope === null) {
            throw new ShouldNotHappenException(__METHOD__ . '() on line ' . __LINE__);
        }

        $propertyFetchType = $nodeScope->getType($propertyFetch->var);
        if ($propertyFetchType instanceof ObjectType) {
            $propertyFetchType = $propertyFetchType->getClassName();
        }

        if (! is_string($propertyFetchType)) {
            return false;
        }

        if (! $this->broker->hasClass($propertyFetchType)) {
            return false;
        }

        $classReflection = $this->broker->getClass($propertyFetchType);
        if (! $classReflection->hasProperty($propertyName)) {
            return false;
        }

        $propertyReflection = $classReflection->getProperty($propertyName, $nodeScope);

        return $propertyReflection->isPublic();
    }
}
