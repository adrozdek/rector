<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\TypeInferer\PropertyTypeInferer;

use PhpParser\Node\Stmt\Property;
use PHPStan\Type\ArrayType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\JoinColumnTagValueNode;
use Rector\DoctrinePhpDocParser\Contract\Ast\PhpDoc\ToManyTagNodeInterface;
use Rector\DoctrinePhpDocParser\Contract\Ast\PhpDoc\ToOneTagNodeInterface;
use Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer\DocBlockManipulator;
use Rector\NodeTypeResolver\PHPStan\Type\TypeFactory;
use Rector\TypeDeclaration\Contract\TypeInferer\PropertyTypeInfererInterface;

final class DoctrineRelationPropertyTypeInferer implements PropertyTypeInfererInterface
{
    /**
     * @var string
     */
    private const COLLECTION_TYPE = 'Doctrine\Common\Collections\Collection';

    /**
     * @var DocBlockManipulator
     */
    private $docBlockManipulator;

    /**
     * @var TypeFactory
     */
    private $typeFactory;

    public function __construct(DocBlockManipulator $docBlockManipulator, TypeFactory $typeFactory)
    {
        $this->docBlockManipulator = $docBlockManipulator;
        $this->typeFactory = $typeFactory;
    }

    public function inferProperty(Property $property): Type
    {
        if ($property->getDocComment() === null) {
            return new MixedType();
        }

        $phpDocInfo = $this->docBlockManipulator->createPhpDocInfoFromNode($property);
        $relationTagValueNode = $phpDocInfo->getDoctrineRelationTagValueNode();
        if ($relationTagValueNode === null) {
            return new MixedType();
        }

        if ($relationTagValueNode instanceof ToManyTagNodeInterface) {
            return $this->processToManyRelation($relationTagValueNode);
        } elseif ($relationTagValueNode instanceof ToOneTagNodeInterface) {
            $joinColumnTagValueNode = $phpDocInfo->getByType(JoinColumnTagValueNode::class);
            return $this->processToOneRelation($relationTagValueNode, $joinColumnTagValueNode);
        }

        return new MixedType();
    }

    public function getPriority(): int
    {
        return 900;
    }

    private function processToManyRelation(ToManyTagNodeInterface $toManyTagNode): Type
    {
        $types = [];

        $targetEntity = $toManyTagNode->getTargetEntity();
        if ($targetEntity) {
            $types[] = new ArrayType(new MixedType(), new ObjectType($targetEntity));
        }

        $types[] = new ObjectType(self::COLLECTION_TYPE);

        return $this->typeFactory->createObjectTypeOrUnionType($types);
    }

    private function processToOneRelation(
        ToOneTagNodeInterface $toOneTagNode,
        ?JoinColumnTagValueNode $joinColumnTagValueNode
    ): Type {
        $types = [];

        $targetEntity = $toOneTagNode->getFqnTargetEntity();
        if ($targetEntity) {
            $types[] = new ObjectType($targetEntity);
        }

        // nullable by default
        if ($joinColumnTagValueNode === null || $joinColumnTagValueNode->isNullable()) {
            $types[] = new NullType();
        }

        return $this->typeFactory->createObjectTypeOrUnionType($types);
    }
}
