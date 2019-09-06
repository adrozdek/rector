<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\TypeInferer\ParamTypeInferer;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Type\ArrayType;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Node\Manipulator\PropertyFetchManipulator;
use Rector\TypeDeclaration\Contract\TypeInferer\ParamTypeInfererInterface;
use Rector\TypeDeclaration\TypeInferer\AbstractTypeInferer;

final class PropertyNodeParamTypeInferer extends AbstractTypeInferer implements ParamTypeInfererInterface
{
    /**
     * @var PropertyFetchManipulator
     */
    private $propertyFetchManipulator;

    public function __construct(PropertyFetchManipulator $propertyFetchManipulator)
    {
        $this->propertyFetchManipulator = $propertyFetchManipulator;
    }

    public function inferParam(Param $param): Type
    {
        /** @var Class_|null $classNode */
        $classNode = $param->getAttribute(AttributeKey::CLASS_NODE);
        if ($classNode === null) {
            return new MixedType();
        }

        $paramName = $this->nameResolver->getName($param);

        /** @var ClassMethod $classMethod */
        $classMethod = $param->getAttribute(AttributeKey::PARENT_NODE);

        $propertyStaticTypes = [];

        $this->callableNodeTraverser->traverseNodesWithCallable($classMethod, function (Node $node) use (
            $paramName,
            &$propertyStaticTypes
        ) {
            if (! $this->propertyFetchManipulator->isVariableAssignToThisPropertyFetch($node, $paramName)) {
                return null;
            }

            /** @var Assign $node */
            $staticType = $this->nodeTypeResolver->getStaticType($node->var);

            /** @var Type|null $staticType */
            if ($staticType !== null) {
                $propertyStaticTypes[] = $staticType;
            }

            return null;
        });

        $propertyStaticTypes = array_unique($propertyStaticTypes);

        if ($propertyStaticTypes === []) {
            return new MixedType();
        }

        if ($propertyStaticTypes[0] instanceof ArrayType) {
            return $propertyStaticTypes[0]->getItemType();
        }

        return new MixedType();
    }
}
