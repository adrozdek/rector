<?php declare(strict_types=1);

namespace Rector\CodingStyle\Rector\Use_;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeVisitor\NameResolver;
use Rector\CodingStyle\Imports\ShortNameResolver;
use Rector\CodingStyle\Naming\ClassNaming;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see \Rector\CodingStyle\Tests\Rector\Use_\RemoveUnusedAliasRector\RemoveUnusedAliasRectorTest
 */
final class RemoveUnusedAliasRector extends AbstractRector
{
    /**
     * @var Node[][][]
     */
    private $resolvedNodeNames = [];

    /**
     * @var string[]
     */
    private $resolvedDocPossibleAliases = [];

    /**
     * @var ClassNaming
     */
    private $classNaming;

    /**
     * @var ShortNameResolver
     */
    private $shortNameResolver;

    public function __construct(ClassNaming $classNaming, ShortNameResolver $shortNameResolver)
    {
        $this->classNaming = $classNaming;
        $this->shortNameResolver = $shortNameResolver;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Removes unused use aliases', [
            new CodeSample(
                <<<'CODE_SAMPLE'
use Symfony\Kernel as BaseKernel;

class SomeClass extends BaseKernel
{
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
use Symfony\Kernel;

class SomeClass extends Kernel
{
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Use_::class];
    }

    /**
     * @param Use_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $this->resolvedNodeNames = [];
        $this->resolveUsedNameNodes($node);

        // collect differentiated aliases
        $useNamesAliasToName = [];

        $shortNames = $this->shortNameResolver->resolveForNode($node);
        foreach ($shortNames as $alias => $useImport) {
            $shortName = $this->classNaming->getShortName($useImport);
            $useNamesAliasToName[$shortName][] = $alias;
        }

        foreach ($node->uses as $use) {
            if ($use->alias === null) {
                continue;
            }

            $lastName = $use->name->getLast();
            $aliasName = $this->getName($use->alias);

            // both are used → nothing to remove
            if (isset($this->resolvedNodeNames[$lastName], $this->resolvedNodeNames[$aliasName])) {
                continue;
            }

            // part of some @Doc annotation
            if (in_array($aliasName, $this->resolvedDocPossibleAliases, true)) {
                continue;
            }

            // only last name is used → no need for alias
            if (isset($this->resolvedNodeNames[$lastName])) {
                $use->alias = null;
                continue;
            }

            // only alias name is used → use last name directly
            if (isset($this->resolvedNodeNames[$aliasName])) {
                // keep to differentiate 2 alaises classes
                if (isset($useNamesAliasToName[$lastName]) && count($useNamesAliasToName[$lastName]) > 1) {
                    continue;
                }

                $this->renameNameNode($this->resolvedNodeNames[$aliasName], $lastName);
                $use->alias = null;
            }
        }

        return $node;
    }

    private function resolveUsedNameNodes(Use_ $node): void
    {
        $searchNode = $this->resolveSearchNode($node);
        if ($searchNode === null) {
            return;
        }

        $this->resolveUsedNames($searchNode);
        $this->resolveUsedClassNames($searchNode);
        $this->resolveTraitUseNames($searchNode);
        $this->resolveDocPossibleAliases($searchNode);
    }

    /**
     * @param Node[][] $usedNameNodes
     */
    private function renameNameNode(array $usedNameNodes, string $lastName): void
    {
        /** @var Identifier|Name $usedName */
        foreach ($usedNameNodes as [$usedName, $parentNode]) {
            if ($parentNode instanceof TraitUse) {
                foreach ($parentNode->traits as $key => $traitName) {
                    if (! $this->areNamesEqual($traitName, $usedName)) {
                        continue;
                    }

                    $parentNode->traits[$key] = new Name($lastName);
                }

                continue;
            }

            if ($parentNode instanceof Class_) {
                if ($parentNode->name !== null) {
                    if ($this->areNamesEqual($parentNode->name, $usedName)) {
                        $parentNode->name = new Identifier($lastName);
                    }
                }

                if ($parentNode->extends !== null) {
                    if ($this->areNamesEqual($parentNode->extends, $usedName)) {
                        $parentNode->extends = new Name($lastName);
                    }
                }

                foreach ($parentNode->implements as $key => $implementNode) {
                    if ($this->areNamesEqual($implementNode, $usedName)) {
                        $parentNode->implements[$key] = new Name($lastName);
                    }
                }

                continue;
            }

            if ($parentNode instanceof Param) {
                if ($parentNode->type !== null) {
                    if ($this->areNamesEqual($parentNode->type, $usedName)) {
                        $parentNode->type = new Name($lastName);
                    }
                }

                continue;
            }

            if ($parentNode instanceof New_) {
                if ($this->areNamesEqual($parentNode->class, $usedName)) {
                    $parentNode->class = new Name($lastName);
                }

                continue;
            }

            if ($parentNode instanceof ClassMethod) {
                if ($parentNode->returnType !== null) {
                    if ($this->areNamesEqual($parentNode->returnType, $usedName)) {
                        $parentNode->returnType = new Name($lastName);
                    }
                }

                continue;
            }

            if ($parentNode instanceof Interface_) {
                foreach ($parentNode->extends as $key => $extendInterfaceName) {
                    if ($this->areNamesEqual($extendInterfaceName, $usedName)) {
                        $parentNode->extends[$key] = new Name($lastName);
                    }
                }

                continue;
            }
        }
    }

    private function resolveSearchNode(Use_ $node): ?Node
    {
        $searchNode = $node->getAttribute(AttributeKey::PARENT_NODE);
        if ($searchNode) {
            return $searchNode;
        }

        $searchNode = $node->getAttribute(AttributeKey::NEXT_NODE);
        if ($searchNode) {
            return $searchNode;
        }

        // skip
        return null;
    }

    private function resolveUsedNames(Node $searchNode): void
    {
        /** @var Name[] $namedNodes */
        $namedNodes = $this->betterNodeFinder->findInstanceOf($searchNode, Name::class);

        foreach ($namedNodes as $nameNode) {
            /** node name before becoming FQN - attribute from @see NameResolver */
            $originalName = $nameNode->getAttribute('originalName');
            if (! $originalName instanceof Name) {
                continue;
            }

            $parentNode = $nameNode->getAttribute(AttributeKey::PARENT_NODE);
            if ($parentNode === null) {
                throw new ShouldNotHappenException(__METHOD__ . '() on line ' . __LINE__);
            }

            $this->resolvedNodeNames[$originalName->toString()][] = [$nameNode, $parentNode];
        }
    }

    private function resolveUsedClassNames(Node $searchNode): void
    {
        /** @var ClassLike[] $classLikes */
        $classLikes = $this->betterNodeFinder->findClassLikes([$searchNode]);

        foreach ($classLikes as $classLikeNode) {
            $name = $this->getName($classLikeNode->name);
            $this->resolvedNodeNames[$name][] = [$classLikeNode->name, $classLikeNode];
        }
    }

    private function resolveTraitUseNames(Node $searchNode): void
    {
        /** @var Identifier[] $identifierNodes */
        $identifierNodes = $this->betterNodeFinder->findInstanceOf($searchNode, Identifier::class);

        foreach ($identifierNodes as $identifierNode) {
            $parentNode = $identifierNode->getAttribute(AttributeKey::PARENT_NODE);
            if (! $parentNode instanceof UseUse) {
                continue;
            }

            $this->resolvedNodeNames[$identifierNode->name][] = [$identifierNode, $parentNode];
        }
    }

    private function resolveDocPossibleAliases(Node $searchNode): void
    {
        $this->traverseNodesWithCallable($searchNode, function (Node $node): void {
            if ($node->getDocComment() === null) {
                return;
            }

            $matches = Strings::matchAll($node->getDocComment()->getText(), '#\@(?<possible_alias>.*?)\\\\#s');
            foreach ($matches as $match) {
                $this->resolvedDocPossibleAliases[] = $match['possible_alias'];
            }
        });
    }
}
