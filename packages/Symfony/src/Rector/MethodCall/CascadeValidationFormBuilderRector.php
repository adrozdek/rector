<?php declare(strict_types=1);

namespace Rector\Symfony\Rector\MethodCall;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Node\Manipulator\ArrayManipulator;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see https://gist.github.com/mickaelandrieu/5d27a2ffafcbdd64912f549aaf2a6df9#stuck-with-forms
 * @see \Rector\Symfony\Tests\Rector\MethodCall\CascadeValidationFormBuilderRector\CascadeValidationFormBuilderRectorTest
 */
final class CascadeValidationFormBuilderRector extends AbstractRector
{
    /**
     * @var ArrayManipulator
     */
    private $arrayManipulator;

    public function __construct(ArrayManipulator $arrayManipulator)
    {
        $this->arrayManipulator = $arrayManipulator;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Change "cascade_validation" option to specific node attribute', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeController
{
    public function someMethod()
    {
        $form = $this->createFormBuilder($article, ['cascade_validation' => true])
            ->add('author', new AuthorType())
            ->getForm();
    }

    protected function createFormBuilder()
    {
        return new FormBuilder();
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
class SomeController
{
    public function someMethod()
    {
        $form = $this->createFormBuilder($article)
            ->add('author', new AuthorType(), [
                'constraints' => new \Symfony\Component\Validator\Constraints\Valid(),
            ])
            ->getForm();
    }

    protected function createFormBuilder()
    {
        return new FormBuilder();
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
        if ($this->shouldSkip($node)) {
            return null;
        }

        /** @var Array_ $formBuilderOptionsArrayNode */
        $formBuilderOptionsArrayNode = $node->args[1]->value;

        if (! $this->findAndRemoveCascadeValidationOption($node, $formBuilderOptionsArrayNode)) {
            return null;
        }

        $this->addConstraintsOptionToFollowingAddMethodCalls($node);

        return $node;
    }

    private function shouldSkip(MethodCall $methodCall): bool
    {
        if (! $this->isName($methodCall, 'createFormBuilder')) {
            return true;
        }

        if (! isset($methodCall->args[1])) {
            return true;
        }

        return ! $methodCall->args[1]->value instanceof Array_;
    }

    private function findAndRemoveCascadeValidationOption(MethodCall $methodCall, Array_ $optionsArrayNode): bool
    {
        foreach ($optionsArrayNode->items as $key => $arrayItem) {
            if (! $this->arrayManipulator->hasKeyName($arrayItem, 'cascade_validation')) {
                continue;
            }

            if (! $this->isTrue($arrayItem->value)) {
                continue;
            }

            unset($optionsArrayNode->items[$key]);

            // remove empty array
            if (count($optionsArrayNode->items) === 0) {
                unset($methodCall->args[1]);
            }

            return true;
        }

        return false;
    }

    private function addConstraintsOptionToFollowingAddMethodCalls(Node $node): void
    {
        $constraintsArrayItem = new ArrayItem(
            new New_(new FullyQualified('Symfony\Component\Validator\Constraints\Valid')),
            new String_('constraints')
        );

        $parentNode = $node->getAttribute(AttributeKey::PARENT_NODE);

        while ($parentNode instanceof MethodCall) {
            if ($this->isName($parentNode, 'add')) {
                /** @var Array_ $addOptionsArrayNode */
                $addOptionsArrayNode = isset($parentNode->args[2]) ? $parentNode->args[2]->value : new Array_();
                $addOptionsArrayNode->items[] = $constraintsArrayItem;

                $parentNode->args[2] = new Arg($addOptionsArrayNode);
            }

            $parentNode = $parentNode->getAttribute(AttributeKey::PARENT_NODE);
        }
    }
}
