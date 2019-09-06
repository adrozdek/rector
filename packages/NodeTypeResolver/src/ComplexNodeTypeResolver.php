<?php declare(strict_types=1);

namespace Rector\NodeTypeResolver;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\Php\VarTypeInfo;
use Rector\Php\TypeAnalyzer;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\PhpParser\Node\Resolver\NameResolver;

final class ComplexNodeTypeResolver
{
    /**
     * @var NameResolver
     */
    private $nameResolver;

    /**
     * @var BetterNodeFinder
     */
    private $betterNodeFinder;

    /**
     * @var NodeTypeResolver
     */
    private $nodeTypeResolver;

    /**
     * @var TypeAnalyzer
     */
    private $typeAnalyzer;

    /**
     * @var StaticTypeMapper
     */
    private $staticTypeMapper;

    public function __construct(
        StaticTypeMapper $staticTypeMapper,
        NameResolver $nameResolver,
        BetterNodeFinder $betterNodeFinder,
        NodeTypeResolver $nodeTypeResolver,
        TypeAnalyzer $typeAnalyzer
    ) {
        $this->staticTypeMapper = $staticTypeMapper;
        $this->nameResolver = $nameResolver;
        $this->betterNodeFinder = $betterNodeFinder;
        $this->nodeTypeResolver = $nodeTypeResolver;
        $this->typeAnalyzer = $typeAnalyzer;
    }

    /**
     * Based on static analysis of code, looking for property assigns
     */
    public function resolvePropertyTypeInfo(Property $property): ?VarTypeInfo
    {
        $types = [];

        $propertyDefault = $property->props[0]->default;
        if ($propertyDefault !== null) {
            $types[] = $this->staticTypeMapper->mapPhpParserNodeToString($propertyDefault);
        }

        $classNode = $property->getAttribute(AttributeKey::CLASS_NODE);
        if (! $classNode instanceof Class_) {
            throw new ShouldNotHappenException(__METHOD__ . '() on line ' . __LINE__);
        }

        $propertyName = $this->nameResolver->getName($property);
        if ($propertyName === null) {
            return null;
        }

        /** @var Assign[] $propertyAssignNodes */
        $propertyAssignNodes = $this->betterNodeFinder->find([$classNode], function (Node $node) use (
            $propertyName
        ): bool {
            if ($node instanceof Assign && $node->var instanceof PropertyFetch) {
                // is property match
                return $this->nameResolver->isName($node->var, $propertyName);
            }

            return false;
        });

        foreach ($propertyAssignNodes as $propertyAssignNode) {
            $types = array_merge(
                $types,
                $this->nodeTypeResolver->resolveNodeToPHPStanType($propertyAssignNode->expr)
            );
        }

        $types = array_filter($types);

        return new VarTypeInfo($types, $this->typeAnalyzer, $types);
    }
}
