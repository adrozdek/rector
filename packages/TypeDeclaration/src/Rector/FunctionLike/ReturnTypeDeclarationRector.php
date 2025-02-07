<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\FunctionLike;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\Php\ReturnTypeInfo;
use Rector\Php\TypeAnalyzer;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;
use Rector\TypeDeclaration\TypeInferer\ReturnTypeInferer;
use Rector\TypeDeclaration\TypeInferer\ReturnTypeInferer\ReturnTypeDeclarationReturnTypeInferer;

/**
 * @sponsor Thanks https://spaceflow.io/ for sponsoring this rule - visit them on https://github.com/SpaceFlow-app
 *
 * @see \Rector\TypeDeclaration\Tests\Rector\FunctionLike\ReturnTypeDeclarationRector\ReturnTypeDeclarationRectorTest
 */
final class ReturnTypeDeclarationRector extends AbstractTypeDeclarationRector
{
    /**
     * @var string[]
     */
    private const EXCLUDED_METHOD_NAMES = ['__construct', '__destruct', '__clone'];

    /**
     * @var ReturnTypeInferer
     */
    private $returnTypeInferer;

    /**
     * @var TypeAnalyzer
     */
    private $typeAnalyzer;

    /**
     * @var bool
     */
    private $overrideExistingReturnTypes = true;

    public function __construct(
        ReturnTypeInferer $returnTypeInferer,
        TypeAnalyzer $typeAnalyzer,
        bool $overrideExistingReturnTypes = true
    ) {
        $this->returnTypeInferer = $returnTypeInferer;
        $this->typeAnalyzer = $typeAnalyzer;
        $this->overrideExistingReturnTypes = $overrideExistingReturnTypes;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Change @return types and type from static analysis to type declarations if not a BC-break',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
<?php

class SomeClass
{
    /**
     * @return int
     */
    public function getCount()
    {
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
<?php

class SomeClass
{
    /**
     * @return int
     */
    public function getCount(): int
    {
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isAtLeastPhpVersion('7.0')) {
            return null;
        }

        if ($this->shouldSkip($node)) {
            return null;
        }

        $inferedTypes = $this->returnTypeInferer->inferFunctionLikeWithExcludedInferers(
            $node,
            [ReturnTypeDeclarationReturnTypeInferer::class]
        );

        if ($inferedTypes === []) {
            return null;
        }

        $returnTypeInfo = new ReturnTypeInfo($inferedTypes, $this->typeAnalyzer, $inferedTypes);
        if ($this->isReturnTypeAlreadyAdded($node, $returnTypeInfo)) {
            return null;
        }

        $shouldPopulateChildren = false;
        // should be previous overridden?
        if ($node->returnType !== null && $returnTypeInfo->getFqnTypeNode() !== null) {
            $isSubtype = $this->isSubtypeOf($returnTypeInfo->getFqnTypeNode(), $node->returnType);

            // @see https://wiki.php.net/rfc/covariant-returns-and-contravariant-parameters
            if ($this->isAtLeastPhpVersion('7.4') && $isSubtype) {
                $shouldPopulateChildren = true;
                $node->returnType = $returnTypeInfo->getFqnTypeNode();
            } elseif ($isSubtype === false) { // type override
                $shouldPopulateChildren = true;
                $node->returnType = $returnTypeInfo->getFqnTypeNode();
            }
        } elseif ($returnTypeInfo->getFqnTypeNode() !== null) {
            $shouldPopulateChildren = true;
            $node->returnType = $returnTypeInfo->getFqnTypeNode();
        }

        if ($shouldPopulateChildren) {
            $this->populateChildren($node, $returnTypeInfo);
        }

        return $node;
    }

    /**
     * Add typehint to all children class methods
     */
    private function populateChildren(Node $node, ReturnTypeInfo $returnTypeInfo): void
    {
        if (! $node instanceof ClassMethod) {
            return;
        }

        $methodName = $this->getName($node);
        if ($methodName === null) {
            throw new ShouldNotHappenException(__METHOD__ . '() on line ' . __LINE__);
        }

        $className = $node->getAttribute(AttributeKey::CLASS_NAME);
        if (! is_string($className)) {
            throw new ShouldNotHappenException(__METHOD__ . '() on line ' . __LINE__);
        }

        $childrenClassLikes = $this->parsedNodesByType->findChildrenOfClass($className);

        // update their methods as well
        foreach ($childrenClassLikes as $childClassLike) {
            $usedTraits = $this->parsedNodesByType->findUsedTraitsInClass($childClassLike);
            foreach ($usedTraits as $trait) {
                $this->addReturnTypeToMethod($trait, $node, $returnTypeInfo);
            }

            $this->addReturnTypeToMethod($childClassLike, $node, $returnTypeInfo);
        }
    }

    private function addReturnTypeToMethod(
        ClassLike $classLike,
        ClassMethod $classMethod,
        ReturnTypeInfo $returnTypeInfo
    ): void {
        $methodName = $this->getName($classMethod);

        $currentClassMethod = $classLike->getMethod($methodName);
        if ($currentClassMethod === null) {
            return;
        }

        // already has a type
        if ($currentClassMethod->returnType !== null) {
            return;
        }

        $resolvedChildType = $this->resolveChildType($returnTypeInfo, $classMethod);
        if ($resolvedChildType === null) {
            return;
        }

        $currentClassMethod->returnType = $resolvedChildType;

        $this->notifyNodeChangeFileInfo($currentClassMethod);
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    private function shouldSkip(Node $node): bool
    {
        if ($this->overrideExistingReturnTypes === false) {
            if ($node->returnType) {
                return true;
            }
        }

        if (! $node instanceof ClassMethod) {
            return false;
        }

        return $this->isNames($node, self::EXCLUDED_METHOD_NAMES);
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    private function isReturnTypeAlreadyAdded(Node $node, ReturnTypeInfo $returnTypeInfo): bool
    {
        if (ltrim($this->print($node->returnType), '\\') === $this->print($returnTypeInfo->getTypeNode())) {
            return true;
        }

        // prevent overriding self with itself
        if ($this->print($node->returnType) === 'self') {
            $className = $node->getAttribute(AttributeKey::CLASS_NAME);

            if (ltrim($this->print($returnTypeInfo->getFqnTypeNode()), '\\') === $className) {
                return true;
            }
        }

        return false;
    }
}
