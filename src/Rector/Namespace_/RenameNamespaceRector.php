<?php declare(strict_types=1);

namespace Rector\Rector\Namespace_;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\ConfiguredCodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see \Rector\Tests\Rector\Namespace_\RenameNamespaceRector\RenameNamespaceRectorTest
 */
final class RenameNamespaceRector extends AbstractRector
{
    /**
     * @var string[]
     */
    private $oldToNewNamespaces = [];

    /**
     * @param string[] $oldToNewNamespaces
     */
    public function __construct(array $oldToNewNamespaces = [])
    {
        $this->oldToNewNamespaces = $oldToNewNamespaces;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Replaces old namespace by new one.', [
            new ConfiguredCodeSample(
                '$someObject = new SomeOldNamespace\SomeClass;',
                '$someObject = new SomeNewNamespace\SomeClass;',
                [
                    '$oldToNewNamespaces' => [
                        'SomeOldNamespace' => 'SomeNewNamespace',
                    ],
                ]
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Namespace_::class, Use_::class, Name::class];
    }

    /**
     * @param Namespace_|Use_|Name $node
     */
    public function refactor(Node $node): ?Node
    {
        $name = $this->getName($node);
        if ($name === null) {
            return null;
        }

        if (! $this->isNamespaceToChange($name)) {
            return null;
        }

        if ($this->isClassFullyQualifiedName($node)) {
            return null;
        }

        if ($node instanceof Namespace_) {
            $newName = $this->resolveNewNameFromNode($name);
            $node->name = new Name($newName);

            return $node;
        }

        if ($node instanceof Use_) {
            $newName = $this->resolveNewNameFromNode($name);
            $node->uses[0]->name = new Name($newName);

            return $node;
        }

        $newName = $this->isPartialNamespace($node) ? $this->resolvePartialNewName(
            $node
        ) : $this->resolveNewNameFromNode($name);

        if ($newName === null) {
            return null;
        }

        $node->parts = explode('\\', $newName);

        return $node;
    }

    private function isNamespaceToChange(string $namespace): bool
    {
        return (bool) $this->getNewNamespaceForOldOne($namespace);
    }

    /**
     * Checks for "new \ClassNoNamespace;"
     * This should be skipped, not a namespace.
     */
    private function isClassFullyQualifiedName(Node $node): bool
    {
        $parentNode = $node->getAttribute(AttributeKey::PARENT_NODE);
        if ($parentNode === null) {
            return false;
        }

        if (! $parentNode instanceof New_) {
            return false;
        }

        /** @var FullyQualified $fullyQualifiedNode */
        $fullyQualifiedNode = $parentNode->class;

        $newClassName = $fullyQualifiedNode->toString();

        return array_key_exists($newClassName, $this->oldToNewNamespaces);
    }

    private function resolveNewNameFromNode(string $name): string
    {
        [$oldNamespace, $newNamespace] = $this->getNewNamespaceForOldOne($name);

        return str_replace($oldNamespace, $newNamespace, $name);
    }

    private function isPartialNamespace(Name $name): bool
    {
        $resolvedName = $name->getAttribute(AttributeKey::RESOLVED_NAME);
        if ($resolvedName === null) {
            return false;
        }

        if ($resolvedName instanceof FullyQualified) {
            return ! $this->isName($name, $resolvedName->toString());
        }

        return false;
    }

    private function resolvePartialNewName(Name $name): ?string
    {
        $nodeName = $this->getName($name);
        if ($nodeName === null) {
            return null;
        }

        $completeNewName = $this->resolveNewNameFromNode($nodeName);

        // first dummy implementation - improve
        $cutOffFromTheLeft = Strings::length($completeNewName) - Strings::length($name->toString());

        return Strings::substring($completeNewName, $cutOffFromTheLeft);
    }

    /**
     * @return string[]
     */
    private function getNewNamespaceForOldOne(string $namespace): array
    {
        /** @var string $oldNamespace */
        foreach ($this->getOldToNewNamespaces() as $oldNamespace => $newNamespace) {
            if (Strings::startsWith($namespace, $oldNamespace)) {
                return [$oldNamespace, $newNamespace];
            }
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function getOldToNewNamespaces(): array
    {
        krsort($this->oldToNewNamespaces);
        return $this->oldToNewNamespaces;
    }
}
