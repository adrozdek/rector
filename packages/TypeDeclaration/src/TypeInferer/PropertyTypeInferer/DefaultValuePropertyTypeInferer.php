<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\TypeInferer\PropertyTypeInferer;

use PhpParser\Node\Stmt\Property;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use Rector\TypeDeclaration\Contract\TypeInferer\PropertyTypeInfererInterface;
use Rector\TypeDeclaration\TypeInferer\AbstractTypeInferer;

final class DefaultValuePropertyTypeInferer extends AbstractTypeInferer implements PropertyTypeInfererInterface
{
    public function inferProperty(Property $property): Type
    {
        $propertyProperty = $property->props[0];
        if ($propertyProperty->default === null) {
            return new MixedType();
        }

        return $this->nodeTypeResolver->getStaticType($propertyProperty->default);
    }

    public function getPriority(): int
    {
        return 700;
    }
}
