<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\TypeInferer\PropertyTypeInferer;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\TypeDeclaration\Contract\TypeInferer\PropertyTypeInfererInterface;
use Rector\TypeDeclaration\TypeInferer\AbstractTypeInferer;
use Rector\TypeDeclaration\TypeInferer\AssignToPropertyTypeInferer;

final class AllAssignNodePropertyTypeInferer extends AbstractTypeInferer implements PropertyTypeInfererInterface
{
    /**
     * @var AssignToPropertyTypeInferer
     */
    private $assignToPropertyTypeInferer;

    public function __construct(AssignToPropertyTypeInferer $assignToPropertyTypeInferer)
    {
        $this->assignToPropertyTypeInferer = $assignToPropertyTypeInferer;
    }

    public function inferProperty(Property $property): Type
    {
        /** @var ClassLike $class */
        $class = $property->getAttribute(AttributeKey::CLASS_NODE);

        $propertyName = $this->nameResolver->getName($property);

        $assignedExprStaticTypes = $this->assignToPropertyTypeInferer->inferPropertyInClassLike($propertyName, $class);
        if ($assignedExprStaticTypes === []) {
            return new MixedType();
        }

        return $this->typeFactory->createObjectTypeOrUnionType($assignedExprStaticTypes);
    }

    public function getPriority(): int
    {
        return 610;
    }
}
