<?php declare(strict_types=1);

namespace Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_;

use Rector\DoctrinePhpDocParser\Ast\PhpDoc\AbstractDoctrineTagValueNode;
use Rector\DoctrinePhpDocParser\Contract\Ast\PhpDoc\InversedByNodeInterface;
use Rector\DoctrinePhpDocParser\Contract\Ast\PhpDoc\MappedByNodeInterface;
use Rector\DoctrinePhpDocParser\Contract\Ast\PhpDoc\ToManyTagNodeInterface;

final class ManyToManyTagValueNode extends AbstractDoctrineTagValueNode implements ToManyTagNodeInterface, MappedByNodeInterface, InversedByNodeInterface
{
    /**
     * @var string
     */
    public const SHORT_NAME = '@ORM\ManyToMany';

    /**
     * @var string
     */
    private $targetEntity;

    /**
     * @var string|null
     */
    private $mappedBy;

    /**
     * @var string|null
     */
    private $inversedBy;

    /**
     * @var mixed[]|null
     */
    private $cascade;

    /**
     * @var string
     */
    private $fetch;

    /**
     * @var bool
     */
    private $orphanRemoval = false;

    /**
     * @var string|null
     */
    private $indexBy;

    /**
     * @var string
     */
    private $fqnTargetEntity;

    /**
     * @param string[] $orderedVisibleItems
     */
    public function __construct(
        string $targetEntity,
        ?string $mappedBy,
        ?string $inversedBy,
        ?array $cascade,
        string $fetch,
        bool $orphanRemoval,
        ?string $indexBy,
        array $orderedVisibleItems,
        string $fqnTargetEntity
    ) {
        $this->targetEntity = $targetEntity;
        $this->mappedBy = $mappedBy;
        $this->inversedBy = $inversedBy;
        $this->cascade = $cascade;
        $this->fetch = $fetch;
        $this->orphanRemoval = $orphanRemoval;
        $this->indexBy = $indexBy;
        $this->orderedVisibleItems = $orderedVisibleItems;
        $this->fqnTargetEntity = $fqnTargetEntity;
    }

    public function __toString(): string
    {
        $contentItems = [];

        $contentItems['targetEntity'] = sprintf('targetEntity="%s"', $this->targetEntity);

        if ($this->mappedBy !== null) {
            $contentItems['mappedBy'] = sprintf('mappedBy="%s"', $this->mappedBy);
        }

        if ($this->inversedBy !== null) {
            $contentItems['inversedBy'] = sprintf('inversedBy="%s"', $this->inversedBy);
        }

        if ($this->cascade) {
            $contentItems['cascade'] = $this->printArrayItem($this->cascade, 'cascade');
        }

        $contentItems['fetch'] = sprintf('fetch="%s"', $this->fetch);
        $contentItems['orphanRemoval'] = sprintf('orphanRemoval=%s', $this->orphanRemoval ? 'true' : 'false');
        $contentItems['indexBy'] = sprintf('indexBy="%s"', $this->indexBy);

        return $this->printContentItems($contentItems);
    }

    public function getTargetEntity(): ?string
    {
        return $this->targetEntity;
    }

    public function getFqnTargetEntity(): string
    {
        return $this->fqnTargetEntity;
    }

    public function getInversedBy(): ?string
    {
        return $this->inversedBy;
    }

    public function getMappedBy(): ?string
    {
        return $this->mappedBy;
    }

    public function removeMappedBy(): void
    {
        $this->mappedBy = null;
    }

    public function removeInversedBy(): void
    {
        $this->inversedBy = null;
    }

    public function changeTargetEntity(string $targetEntity): void
    {
        $this->targetEntity = $targetEntity;
    }
}
