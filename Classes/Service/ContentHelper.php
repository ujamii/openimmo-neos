<?php

namespace Ujamii\OpenImmo\Service;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeTemplate;
use Neos\ContentRepository\Exception\ImportException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Eel\Exception;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\ImageRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Neos\Controller\Exception\NodeNotFoundException;

class ContentHelper
{
    /**
     * @var \Neos\ContentRepository\Domain\Service\NodeTypeManager
     * @Flow\Inject
     */
    protected $nodeTypeManger;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @var \Neos\ContentRepository\Domain\Service\ContextFactoryInterface
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var PersistenceManagerInterface
     * @Flow\Inject
     */
    protected $persistenceManager;

    /**
     * @var ResourceManager
     * @Flow\Inject
     */
    protected $resourceManager;

    /**
     * @var ImageRepository
     * @Flow\Inject
     */
    protected $imageRepository;

    /**
     * @var AssetRepository
     * @Flow\Inject
     */
    protected $assetRepository;

    /**
     * @var TagRepository
     * @Flow\Inject
     */
    protected $tagRepository;

    /**
     * @param string $nodeType
     * @param string $parentNodeIdentifier
     * @param array $properties
     * @param string|null $nodeName
     * @param bool $hideNode
     *
     * @param \Closure|null $callback
     *
     * @return NodeInterface
     * @throws NodeNotFoundException
     * @throws NodeTypeNotFoundException
     */
    public function createNodeFromTemplateInParent(
        string $nodeType,
        string $parentNodeIdentifier,
        array $properties = [],
        string $nodeName = null,
        bool $hideNode = false,
        \Closure $callback = null
    ): ?NodeInterface {
        $nodeTemplate = new NodeTemplate();
        $nodeTemplate->setNodeType($this->nodeTypeManger->getNodeType($nodeType));

        foreach ($properties as $name => $value) {
            $nodeTemplate->setProperty($name, $value);
        }

        $context = $this->contextFactory->create();
        /* @var Node $parentNode */
        $parentNode = $context->getNodeByIdentifier($parentNodeIdentifier);

        if (\is_null($parentNode)) {
            throw new NodeNotFoundException("Node {$parentNodeIdentifier} not found!");
        }

        $node = null;
        // the creation of the new content has to be done without security checks because creation of content as "Everybody" is prohibited.
        $this->securityContext->withoutAuthorizationChecks(function () use ($parentNode, $nodeTemplate, $nodeName, &$node, $hideNode, $callback) {
            $node = $parentNode->createNodeFromTemplate($nodeTemplate, $nodeName);
            // the new content must not be public in live workspace. So either create it in the user workspace OR hide it in live.
            $node->setHidden($hideNode);
            if ( ! \is_null($callback)) {
                $callback->__invoke($node);
            }
        });

        return $node;
    }

    /**
     * @param string $nodeType
     * @param array $filter
     *
     * @return mixed
     * @throws Exception
     */
    public function findNode(string $nodeType, array $filter = [])
    {
        $context = $this->contextFactory->create(['workspaceName' => 'live']);
        $q       = new FlowQuery([$context->getRootNode()]);

        $filterString = '';
        foreach ($filter as $searchProperty => $searchValue) {
            $filterString .= "[{$searchProperty} = '{$searchValue}']";
        }

        return $q->find("[instanceof {$nodeType}]{$filterString}")->get(0);
    }

    /**
     * @param string $nodeType
     *
     * @return array
     * @throws Exception
     */
    public function findNodesByNodeType(string $nodeType): array
    {
        $context = $this->contextFactory->create(['workspaceName' => 'live']);
        $q       = new FlowQuery([$context->getRootNode()]);

        return $q->find("[instanceof {$nodeType}]")->get();
    }

    /**
     * @param string $remoteImageUrl
     *
     * @return Image|null
     * @throws ImportException
     */
    public function importImage(string $remoteImageUrl): ?Image
    {
        try {
            $resource = $this->resourceManager->importResource($remoteImageUrl);
            if ($resource) {
                $image = new Image($resource);
                $image->addTag($this->getDefaultTag());
                $this->imageRepository->add($image);

                return $image;
            }
        } catch (\Exception $e) {
            // no image :-(
            throw new ImportException("Image could not be imported. Reason: {$e->getMessage()}", 1583856335, $e);
        }

        return null;
    }

    /**
     * @param string $remoteFile
     *
     * @return Asset|null
     * @throws ImportException
     */
    public function importAsset(string $remoteFile): ?Asset
    {
        try {
            $resource = $this->resourceManager->importResource($remoteFile);
            if ($resource) {
                $asset = new Asset($resource);
                $asset->addTag($this->getDefaultTag());
                $this->assetRepository->add($asset);

                return $asset;
            }
        } catch (\Exception $e) {
            // no asset :-(
            throw new ImportException("Asset could not be imported. Reason: {$e->getMessage()}", 1583856335, $e);
        }

        return null;
    }

    /**
     * @return Tag
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     */
    protected function getDefaultTag(): Tag
    {
        $tag = $this->tagRepository->findOneByLabel('openimmo');
        if (!$tag) {
            $tag = new Tag('openimmo');
            $this->tagRepository->add($tag);
            $this->persistenceManager->persistAll();
        }
        return $tag;
    }
}
