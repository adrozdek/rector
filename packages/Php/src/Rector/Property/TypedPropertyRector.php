<?php declare(strict_types=1);

namespace Rector\Php\Rector\Property;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;
use Rector\NodeTypeResolver\ComplexNodeTypeResolver;
use Rector\NodeTypeResolver\Php\VarTypeInfo;
use Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer\DocBlockManipulator;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @source https://wiki.php.net/rfc/typed_properties_v2#proposal
 * @see \Rector\Php\Tests\Rector\Property\TypedPropertyRector\TypedPropertyRectorTest
 */
final class TypedPropertyRector extends AbstractRector
{
    /**
     * @var string[][]
     */
    private $typeNameToAllowedDefaultNodeType = [
        'string' => [String_::class],
        'bool' => [ConstFetch::class],
        'array' => [Array_::class],
        'float' => [DNumber::class, LNumber::class],
        'int' => [LNumber::class],
        'iterable' => [Array_::class],
    ];

    /**
     * @var DocBlockManipulator
     */
    private $docBlockManipulator;

    /**
     * @var ComplexNodeTypeResolver
     */
    private $complexNodeTypeResolver;

    public function __construct(
        DocBlockManipulator $docBlockManipulator,
        ComplexNodeTypeResolver $complexNodeTypeResolver
    ) {
        $this->docBlockManipulator = $docBlockManipulator;
        $this->complexNodeTypeResolver = $complexNodeTypeResolver;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Changes property `@var` annotations from annotation to type.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
final class SomeClass 
{
    /** 
     * @var int 
     */
    private count; 
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
final class SomeClass 
{
    private int count; 
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Property::class];
    }

    /**
     * @param Property $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isAtLeastPhpVersion('7.4')) {
            return null;
        }

        // type is already set → skip
        if ($node->type !== null) {
            return null;
        }

        $varTypeInfos = [];
        // non FQN, so they are 1:1 to possible imported doc type
        $varTypeInfos[] = $this->docBlockManipulator->getVarTypeInfo($node);
        $varTypeInfos[] = $this->complexNodeTypeResolver->resolvePropertyTypeInfo($node);

        $varTypeInfos = array_filter($varTypeInfos);

        foreach ($varTypeInfos as $varTypeInfo) {
            /** @var VarTypeInfo $varTypeInfo */
            if (! $varTypeInfo->isTypehintAble()) {
                continue;
            }

            if ($this->matchesDocTypeAndDefaultValueType($varTypeInfo, $node)) {
                $node->type = $varTypeInfo->getTypeNode();

                return $node;
            }
        }

        return null;
    }

    private function matchesDocTypeAndDefaultValueType(VarTypeInfo $varTypeInfo, Property $property): bool
    {
        $defaultValueNode = $property->props[0]->default;
        if ($defaultValueNode === null) {
            return true;
        }

        if (! isset($this->typeNameToAllowedDefaultNodeType[$varTypeInfo->getType()])) {
            return true;
        }

        if ($varTypeInfo->isNullable()) {
            // is default value "null"?
            return $this->isNull($defaultValueNode);
        }

        $allowedDefaultNodeTypes = $this->typeNameToAllowedDefaultNodeType[$varTypeInfo->getType()];

        return $this->matchesDefaultValueToExpectedNodeTypes($varTypeInfo, $allowedDefaultNodeTypes, $defaultValueNode);
    }

    /**
     * @param string[] $allowedDefaultNodeTypes
     */
    private function matchesDefaultValueToExpectedNodeTypes(
        VarTypeInfo $varTypeInfo,
        array $allowedDefaultNodeTypes,
        Expr $expr
    ): bool {
        foreach ($allowedDefaultNodeTypes as $allowedDefaultNodeType) {
            if (is_a($expr, $allowedDefaultNodeType, true)) {
                if ($varTypeInfo->getType() === 'bool') {
                    return $this->isBool($expr);
                }

                return true;
            }
        }

        return false;
    }
}
