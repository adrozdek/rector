<?php declare(strict_types=1);

namespace Rector\Rector\Architecture\RepositoryAsService;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Type\ObjectType;
use Rector\Bridge\Contract\DoctrineEntityAndRepositoryMapperInterface;
use Rector\Exception\Bridge\RectorProviderException;
use Rector\Exception\ShouldNotHappenException;
use Rector\Naming\PropertyNaming;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

final class ServiceLocatorToDIRector extends AbstractRector
{
    /**
     * @var DoctrineEntityAndRepositoryMapperInterface
     */
    private $doctrineEntityAndRepositoryMapper;

    /**
     * @var PropertyNaming
     */
    private $propertyNaming;

    public function __construct(
        DoctrineEntityAndRepositoryMapperInterface $doctrineEntityAndRepositoryMapper,
        PropertyNaming $propertyNaming
    ) {
        $this->doctrineEntityAndRepositoryMapper = $doctrineEntityAndRepositoryMapper;
        $this->propertyNaming = $propertyNaming;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Turns "$this->getRepository()" in Symfony Controller to constructor injection and private property access.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class ProductController extends Controller
{
    public function someAction()
    {
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->getRepository('SomethingBundle:Product')->findSomething(...);
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class ProductController extends Controller
{
    /**
     * @var ProductRepository
     */
    private $productRepository;

    public function __construct(ProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    public function someAction()
    {
        $entityManager = $this->getDoctrine()->getManager();
        $this->productRepository->findSomething(...);
    }
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
        return [MethodCall::class];
    }

    /**
     * @param MethodCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isName($node, 'getRepository')) {
            return null;
        }

        /** @var string|null $className */
        $className = $node->getAttribute(AttributeKey::CLASS_NAME);
        if ($className === null) {
            return null;
        }

        /** @var MethodCall $methodCallNode */
        $methodCallNode = $node;
        if (count($methodCallNode->args) !== 1) {
            return null;
        }

        if ($methodCallNode->args[0]->value instanceof String_) {
            /** @var String_ $string */
            $string = $methodCallNode->args[0]->value;

            // is alias
            if (Strings::contains($string->value, ':')) {
                return null;
            }
        }

        if (Strings::endsWith($className, 'Repository')) {
            return null;
        }

        $repositoryFqn = $this->resolveRepositoryFqnFromGetRepositoryMethodCall($node);
        $classNode = $node->getAttribute(AttributeKey::CLASS_NODE);
        if (! $classNode instanceof Class_) {
            return null;
        }

        $repositoryObjectType = new ObjectType($repositoryFqn);

        $this->addPropertyToClass(
            $classNode,
            $repositoryObjectType,
            $this->propertyNaming->fqnToVariableName($repositoryObjectType)
        );

        return $this->createPropertyFetch('this', $this->propertyNaming->fqnToVariableName($repositoryObjectType));
    }

    private function resolveRepositoryFqnFromGetRepositoryMethodCall(MethodCall $methodCall): string
    {
        $entityFqnOrAlias = $this->entityFqnOrAlias($methodCall);

        if ($entityFqnOrAlias !== null) {
            $repositoryClassName = $this->doctrineEntityAndRepositoryMapper->mapEntityToRepository($entityFqnOrAlias);
            if ($repositoryClassName !== null) {
                return $repositoryClassName;
            }
        }

        throw new RectorProviderException(sprintf(
            'A repository was not provided for "%s" entity by your "%s" class.',
            $entityFqnOrAlias,
            get_class($this->doctrineEntityAndRepositoryMapper)
        ));
    }

    private function entityFqnOrAlias(MethodCall $methodCall): string
    {
        $repositoryArgument = $methodCall->args[0]->value;

        if ($repositoryArgument instanceof String_) {
            return $repositoryArgument->value;
        }

        if ($repositoryArgument instanceof ClassConstFetch && $repositoryArgument->class instanceof Name) {
            return $this->getName($repositoryArgument->class);
        }

        throw new ShouldNotHappenException('Unable to resolve repository argument');
    }
}
