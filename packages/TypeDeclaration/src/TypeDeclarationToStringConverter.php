<?php declare(strict_types=1);

namespace Rector\TypeDeclaration;

use PhpParser\Node\FunctionLike;
use PhpParser\Node\NullableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use Rector\NodeTypeResolver\PHPStan\Type\TypeFactory;
use Rector\PhpParser\Node\Resolver\NameResolver;

final class TypeDeclarationToStringConverter
{
    /**
     * @var NameResolver
     */
    private $nameResolver;

    /**
     * @var TypeFactory
     */
    private $typeFactory;

    public function __construct(NameResolver $nameResolver, TypeFactory $typeFactory)
    {
        $this->nameResolver = $nameResolver;
        $this->typeFactory = $typeFactory;
    }

    public function resolveFunctionLikeReturnTypeToPHPStanType(FunctionLike $functionLike): Type
    {
        if ($functionLike->getReturnType() === null) {
            return new MixedType();
        }

        $types = [];

        $returnType = $functionLike->getReturnType();
        $type = $returnType instanceof NullableType ? $returnType->type : $returnType;

        // @todo static mapper, phpParserNodeToPHPSTanType
        $result = $this->nameResolver->getName($type);
        if ($result !== null) {
            $types[] = $result;
        }

        if ($returnType instanceof NullableType) {
            $types[] = new NullType();
        }

        return $this->typeFactory->createObjectTypeOrUnionType($types);
    }
}
