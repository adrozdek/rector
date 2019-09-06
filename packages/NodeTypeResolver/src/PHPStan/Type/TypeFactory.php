<?php declare(strict_types=1);

namespace Rector\NodeTypeResolver\PHPStan\Type;

use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Rector\Exception\ShouldNotHappenException;
use Rector\PHPStan\TypeFactoryStaticHelper;

final class TypeFactory
{
    /**
     * @param Type[] $types
     */
    public function createMixedPassedOrUnionType(array $types): Type
    {
        $types = $this->unwrapUnionedTypes($types);

        if (count($types) === 0) {
            return new MixedType();
        }

        if (count($types) === 1) {
            return $types[0];
        }

        return new UnionType($types);
    }

    /**
     * @param string[] $allTypes
     * @return ObjectType|UnionType
     */
    public function createObjectTypeOrUnionType(array $allTypes): Type
    {
        if (count($allTypes) === 1) {
            return new ObjectType($allTypes[0]);
        }

        if (count($allTypes) > 1) {
            // keep original order, UnionType internally overrides it → impossible to get first type back, e.g. class over interface
            return TypeFactoryStaticHelper::createUnionObjectType($allTypes);
        }

        throw new ShouldNotHappenException();
    }

    private function unwrapUnionedTypes(array $types): array
    {
        // unwrap union types
        $unwrappedTypes = [];
        foreach ($types as $key => $type) {
            if ($type instanceof UnionType) {
                foreach ($type->getTypes() as $subUnionedType) {
                    $unwrappedTypes[] = $subUnionedType;
                }

                unset($types[$key]);
            }
        }

        return array_merge($types, $unwrappedTypes);
    }
}
