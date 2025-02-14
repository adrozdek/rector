<?php declare(strict_types=1);

namespace Rector\Php\Rector\Assign;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Coalesce as AssignCoalesce;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see https://wiki.php.net/rfc/null_coalesce_equal_operator
 * @see \Rector\Php\Tests\Rector\Assign\NullCoalescingOperatorRector\NullCoalescingOperatorRectorTest
 */
final class NullCoalescingOperatorRector extends AbstractRector
{
    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Use null coalescing operator ??=', [
            new CodeSample(
                <<<'CODE_SAMPLE'
$array = [];
$array['user_id'] = $array['user_id'] ?? 'value';
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
$array = [];
$array['user_id'] ??= 'value';
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Assign::class];
    }

    /**
     * @param Assign $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isAtLeastPhpVersion('7.4')) {
            return null;
        }

        if (! $node->expr instanceof Coalesce) {
            return null;
        }

        if (! $this->areNodesEqual($node->var, $node->expr->left)) {
            return null;
        }

        return new AssignCoalesce($node->var, $node->expr->right);
    }
}
