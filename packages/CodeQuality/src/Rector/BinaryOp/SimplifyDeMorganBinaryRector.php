<?php declare(strict_types=1);

namespace Rector\CodeQuality\Rector\BinaryOp;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BooleanNot;
use Rector\PhpParser\Node\AssignAndBinaryMap;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see https://robots.thoughtbot.com/clearer-conditionals-using-de-morgans-laws
 * @see https://stackoverflow.com/questions/20043664/de-morgans-law
 * @see \Rector\CodeQuality\Tests\Rector\BinaryOp\SimplifyDeMorganBinaryRector\SimplifyDeMorganBinaryRectorTest
 */
final class SimplifyDeMorganBinaryRector extends AbstractRector
{
    /**
     * @var AssignAndBinaryMap
     */
    private $assignAndBinaryMap;

    public function __construct(AssignAndBinaryMap $assignAndBinaryMap)
    {
        $this->assignAndBinaryMap = $assignAndBinaryMap;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Simplify negated conditions with de Morgan theorem', [
            new CodeSample(
                <<<'CODE_SAMPLE'
<?php

$a = 5;
$b = 10;
$result = !($a > 20 || $b <= 50);
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
<?php

$a = 5;
$b = 10;
$result = $a <= 20 && $b > 50;
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [BooleanNot::class];
    }

    /**
     * @param BooleanNot $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $node->expr instanceof BinaryOp) {
            return null;
        }

        // and is simpler to read → keep it
        if ($node->expr instanceof BooleanAnd) {
            return null;
        }

        $inversedNode = $this->assignAndBinaryMap->getInversed($node->expr);
        if ($inversedNode === null) {
            if ($node->expr instanceof BooleanOr) {
                $inversedNode = BooleanAnd::class;
            } else {
                return null;
            }
        }

        // no nesting
        if ($node->expr->left instanceof BooleanOr) {
            return null;
        }

        if ($node->expr->right instanceof BooleanOr) {
            return null;
        }

        return new $inversedNode($this->inverseNode($node->expr->left), $this->inverseNode($node->expr->right));
    }

    private function inverseNode(Expr $expr): Node
    {
        if ($expr instanceof BinaryOp) {
            $inversedBinaryOp = $this->assignAndBinaryMap->getInversed($expr);
            if ($inversedBinaryOp) {
                return new $inversedBinaryOp($expr->left, $expr->right);
            }
        }

        if ($expr instanceof BooleanNot) {
            return $expr->expr;
        }

        return new BooleanNot($expr);
    }
}
