<?php declare(strict_types=1);

namespace Rector\Symfony\Rector\Controller;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Type\ObjectType;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Node\Manipulator\ChainMethodCallManipulator;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see \Rector\Symfony\Tests\Rector\Controller\AddFlashRector\AddFlashRectorTest
 */
final class AddFlashRector extends AbstractRector
{
    /**
     * @var string
     */
    private $controllerClass;

    /**
     * @var ChainMethodCallManipulator
     */
    private $chainMethodCallManipulator;

    public function __construct(
        ChainMethodCallManipulator $chainMethodCallManipulator,
        string $controllerClass = 'Symfony\Bundle\FrameworkBundle\Controller\Controller'
    ) {
        $this->chainMethodCallManipulator = $chainMethodCallManipulator;
        $this->controllerClass = $controllerClass;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Turns long flash adding to short helper method in Controller in Symfony', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeController extends Controller
{
    public function some(Request $request)
    {
        $request->getSession()->getFlashBag()->add("success", "something");
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
class SomeController extends Controller
{
    public function some(Request $request)
    {
        $this->addFlash("success", "something");
    }
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
        return [MethodCall::class];
    }

    /**
     * @param MethodCall $node
     */
    public function refactor(Node $node): ?Node
    {
        $parentClassName = $node->getAttribute(AttributeKey::PARENT_CLASS_NAME);
        if ($parentClassName !== $this->controllerClass) {
            return null;
        }

        if (! $this->chainMethodCallManipulator->isTypeAndChainCalls(
            $node,
            new ObjectType('Symfony\Component\HttpFoundation\Request'),
            ['getSession', 'getFlashBag', 'add']
        )
        ) {
            return null;
        }

        return $this->createMethodCall('this', 'addFlash', $node->args);
    }
}
