<?php declare(strict_types=1);

namespace Rector\Php\Rector\FuncCall;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use Rector\Php\Regex\RegexPatternArgumentManipulator;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see https://3v4l.org/dRG8U
 * @see \Rector\Php\Tests\Rector\FuncCall\RegexDashEscapeRector\RegexDashEscapeRectorTest
 */
final class RegexDashEscapeRector extends AbstractRector
{
    /**
     * @var string
     */
    private const LEFT_HAND_UNESCAPED_DASH_PATTERN = '#(\\\\(w|s|d))-(?!\])#i';

    /**
     * @var string
     * @see https://regex101.com/r/TBVme9/1
     */
    private const RIGHT_HAND_UNESCAPED_DASH_PATTERN = '#(?<!\[)-\\\\(w|s|d)#i';

    /**
     * @var RegexPatternArgumentManipulator
     */
    private $regexPatternArgumentManipulator;

    public function __construct(
        BetterNodeFinder $betterNodeFinder,
        RegexPatternArgumentManipulator $regexPatternArgumentManipulator
    ) {
        $this->betterNodeFinder = $betterNodeFinder;
        $this->regexPatternArgumentManipulator = $regexPatternArgumentManipulator;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Escape - in some cases', [
            new CodeSample(
                <<<'CODE_SAMPLE'
preg_match("#[\w-()]#", 'some text');
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
preg_match("#[\w\-()]#", 'some text');
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [FuncCall::class, StaticCall::class];
    }

    /**
     * @param FuncCall|StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        $regexArguments = $this->regexPatternArgumentManipulator->matchCallArgumentWithRegexPattern($node);
        if ($regexArguments === []) {
            return null;
        }

        foreach ($regexArguments as $regexArgument) {
            $this->escapeStringNode($regexArgument);
        }

        return $node;
    }

    private function escapeStringNode(String_ $stringNode): void
    {
        $stringValue = $stringNode->value;

        if (Strings::match($stringValue, self::LEFT_HAND_UNESCAPED_DASH_PATTERN)) {
            $stringNode->value = Strings::replace($stringValue, self::LEFT_HAND_UNESCAPED_DASH_PATTERN, '$1\-');
            // helped needed to skip re-escaping regular expression
            $stringNode->setAttribute('is_regular_pattern', true);
            return;
        }

        if (Strings::match($stringValue, self::RIGHT_HAND_UNESCAPED_DASH_PATTERN)) {
            $stringNode->value = Strings::replace($stringValue, self::RIGHT_HAND_UNESCAPED_DASH_PATTERN, '\-$2');
            // helped needed to skip re-escaping regular expression
            $stringNode->setAttribute('is_regular_pattern', true);
        }
    }
}
