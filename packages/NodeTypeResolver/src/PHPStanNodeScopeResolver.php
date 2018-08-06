<?php declare(strict_types=1);

namespace Rector\NodeTypeResolver;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeVisitor\NameResolver;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\Broker;
use Rector\Configuration\Option;
use Rector\Exception\ShouldNotHappenException;
use Rector\FileSystem\FilesFinder;
use Rector\Node\Attribute;
use Rector\NodeTypeResolver\Configuration\CurrentFileProvider;
use Rector\NodeTypeResolver\PHPStan\Scope\ScopeFactory;
use Symplify\PackageBuilder\Parameter\ParameterProvider;
use Symplify\PackageBuilder\Reflection\PrivatesAccessor;

/**
 * @inspired by https://github.com/silverstripe/silverstripe-upgrader/blob/532182b23e854d02e0b27e68ebc394f436de0682/src/UpgradeRule/PHP/Visitor/PHPStanScopeVisitor.php
 * - https://github.com/silverstripe/silverstripe-upgrader/pull/57/commits/e5c7cfa166ad940d9d4ff69537d9f7608e992359#diff-5e0807bb3dc03d6a8d8b6ad049abd774
 */
final class PHPStanNodeScopeResolver
{
    /**
     * @var NodeScopeResolver
     */
    private $nodeScopeResolver;

    /**
     * @var CurrentFileProvider
     */
    private $currentFileProvider;

    /**
     * @var ParameterProvider
     */
    private $parameterProvider;

    /**
     * @var FilesFinder
     */
    private $filesFinder;

    /**
     * @var ScopeFactory
     */
    private $scopeFactory;

    /**
     * @var Broker
     */
    private $broker;

    public function __construct(
        CurrentFileProvider $currentFileProvider,
        ParameterProvider $parameterProvider,
        FilesFinder $filesFinder,
        ScopeFactory $scopeFactory,
        NodeScopeResolver $nodeScopeResolver,
        Broker $broker
    ) {
        $this->currentFileProvider = $currentFileProvider;
        $this->parameterProvider = $parameterProvider;
        $this->filesFinder = $filesFinder;
        $this->scopeFactory = $scopeFactory;
        $this->nodeScopeResolver = $nodeScopeResolver;
        $this->broker = $broker;
    }

    /**
     * @param Node[] $nodes
     * @return Node[]
     */
    public function processNodes(array $nodes): array
    {
        $this->ensureNameResolverWasRun($nodes);
        $this->setAnalysedFiles();

        $this->nodeScopeResolver->processNodes(
            $nodes,
            $this->scopeFactory->createFromFileInfo($this->currentFileProvider->getSplFileInfo()),
            function (Node $node, Scope $scope): void {
                // the class reflection is resolved AFTER entering to class node
                // so we need to get it from the first after this one
                if ($node instanceof Class_ || $node instanceof Interface_) {
                    if (isset($node->namespacedName)) {
                        $scope = $scope->enterClass($this->broker->getClass((string) $node->namespacedName));
                    } else {
                        // possibly anonymous class
                        $anonymousClassReflection = (new PrivatesAccessor())->getPrivateProperty(
                            $this->nodeScopeResolver,
                            'anonymousClassReflection'
                        );

                        if ($anonymousClassReflection) {
                            $scope = $scope->enterAnonymousClass($anonymousClassReflection);
                        }
                    }
                }

                $node->setAttribute(Attribute::SCOPE, $scope);
            }
        );

        return $nodes;
    }

    private function setAnalysedFiles(): void
    {
        $source = $this->parameterProvider->provideParameter(Option::SOURCE);
        $phpFileInfos = $this->filesFinder->findInDirectoriesAndFiles($source, ['php']);

        $filePaths = [];
        foreach ($phpFileInfos as $phpFileInfo) {
            $filePaths[] = $phpFileInfo->getRealPath();
        }

        $this->nodeScopeResolver->setAnalysedFiles($filePaths);
    }

    /**
     * @param Node[] $nodes
     */
    private function ensureNameResolverWasRun(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Class_) {
                if (isset($node->namespacedName)) {
                    return;
                }

                throw new ShouldNotHappenException(sprintf(
                    '"%s" node needs "namespacedNode" property set via "%s" Node Traverser. Did you forget to run it before calling "%s->processNodes()"?.',
                    get_class($node),
                    NameResolver::class,
                    self::class
                ));
            }
        }
    }
}
