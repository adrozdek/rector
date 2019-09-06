<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\TypeInferer\ParamTypeInferer;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\Php\ReturnTypeInfo;
use Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer\DocBlockManipulator;
use Rector\PhpParser\Node\Manipulator\PropertyFetchManipulator;
use Rector\TypeDeclaration\Contract\TypeInferer\ParamTypeInfererInterface;
use Rector\TypeDeclaration\TypeInferer\AbstractTypeInferer;

final class GetterNodeParamTypeInferer extends AbstractTypeInferer implements ParamTypeInfererInterface
{
    /**
     * @var PropertyFetchManipulator
     */
    private $propertyFetchManipulator;

    /**
     * @var DocBlockManipulator
     */
    private $docBlockManipulator;

    public function __construct(
        PropertyFetchManipulator $propertyFetchManipulator,
        DocBlockManipulator $docBlockManipulator
    ) {
        $this->propertyFetchManipulator = $propertyFetchManipulator;
        $this->docBlockManipulator = $docBlockManipulator;
    }

    public function inferParam(Param $param): Type
    {
        /** @var Class_|null $classNode */
        $classNode = $param->getAttribute(AttributeKey::CLASS_NODE);
        if ($classNode === null) {
            return new MixedType();
        }

        /** @var ClassMethod $classMethod */
        $classMethod = $param->getAttribute(AttributeKey::PARENT_NODE);

        /** @var string $paramName */
        $paramName = $this->nameResolver->getName($param);

        $propertyNames = $this->propertyFetchManipulator->getPropertyNamesOfAssignOfVariable($classMethod, $paramName);
        if ($propertyNames === []) {
            return new MixedType();
        }

        $returnTypeInfo = null;

        // resolve property assigns
        $this->callableNodeTraverser->traverseNodesWithCallable($classNode, function (Node $node) use (
            $propertyNames,
            &$returnTypeInfo
        ): ?int {
            if (! $node instanceof Return_ || $node->expr === null) {
                return null;
            }

            $isMatch = $this->propertyFetchManipulator->isLocalPropertyOfNames($node->expr, $propertyNames);
            if (! $isMatch) {
                return null;
            }

            // what is return type?
            /** @var ClassMethod|null $methodNode */
            $methodNode = $node->getAttribute(AttributeKey::METHOD_NODE);
            if (! $methodNode instanceof ClassMethod) {
                return null;
            }

            $methodReturnTypeInfo = $this->docBlockManipulator->getReturnTypeInfo($methodNode);
            if ($methodReturnTypeInfo === null) {
                return null;
            }

            $returnTypeInfo = $methodReturnTypeInfo;

            return NodeTraverser::STOP_TRAVERSAL;
        });

        /** @var ReturnTypeInfo|null $returnTypeInfo */
        if ($returnTypeInfo === null) {
            return new MixedType();
        }

        $docTypes = $returnTypeInfo->getDocTypes();

        // remove "[]" for the php doc nodes, as they will be already arrayed
        foreach ($docTypes as $key => $docType) {
            $docTypes[$key] = rtrim($docType, '[]');
        }

        return $this->typeFactory->createObjectTypeOrUnionType($docTypes);
    }
}
