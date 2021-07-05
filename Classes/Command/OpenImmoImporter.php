<?php

namespace Ujamii\OpenImmo\Command;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Collections\ArrayCollection;
use JMS\Serializer\Handler\HandlerRegistryInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\NodeType\NodeTypeConstraints;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodes;
use Neos\ContentRepository\Exception\ImportException;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\ConsoleOutput;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Controller\Exception\NodeNotFoundException;
use Neos\Utility\Files;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Ujamii\OpenImmo\API\Aktion;
use Ujamii\OpenImmo\API\Daten;
use Ujamii\OpenImmo\API\Immobilie;
use Ujamii\OpenImmo\API\Openimmo;
use Ujamii\OpenImmo\Handler\DateTimeHandler;
use Ujamii\OpenImmo\Service\ContentHelper;

class OpenImmoImporter
{
    public const OPEN_IMMO_DOCUMENT_IMMOBILIE = 'Ujamii.OpenImmo:Document.Immobilie';

    /**
     * @Flow\InjectConfiguration(path="import")
     * @var array
     */
    protected $importConfig;

    /**
     * @var ContentHelper
     * @Flow\Inject
     */
    protected $contentHelper;

    /**
     * @var PersistenceManagerInterface
     * @Flow\Inject
     */
    protected $persistenceManager;

    /**
     * @var LoggerInterface
     * @Flow\Inject
     */
    protected $logger;

    /**
     * @var ArrayCollection
     */
    protected $nodesToBeDeactivated;

    /**
     * @var ConsoleOutput
     */
    private $output;

    public function __construct(ConsoleOutput $output)
    {
        $this->output = $output;
    }

    public function importData(): void
    {
        $this->nodesToBeDeactivated = new ArrayCollection();
        $importSourceDirectory      = $this->importConfig['sourceDirectory'];
        $this->output->outputLine("Importing ZIP files from directory {$importSourceDirectory} ...");

        $finder = new Finder();
        $finder->files()->name('*.zip')->in($importSourceDirectory)->sortByName();

        if ($finder->hasResults()) {
            foreach ($finder as $zipFile) {
                // unzip file
                $finder   = new ExecutableFinder;
                $hasUnzip = (bool)$finder->find('unzip');
                if ($hasUnzip) {
                    $directoryName           = $zipFile->getFilenameWithoutExtension();
                    $absoluteTargetDirectory = $importSourceDirectory . $directoryName;
                    if (is_dir($absoluteTargetDirectory)) {
                        $this->output->outputLine("<info>directory {$directoryName} already exists, so delete it!</info>");
                        Files::removeDirectoryRecursively($importSourceDirectory . $directoryName);
                    }
                    $this->output->outputLine("<info>extracting {$zipFile->getRealPath()} to {$absoluteTargetDirectory}</info>");
                    $process = new Process(['unzip', $zipFile->getRealPath(), "-d{$absoluteTargetDirectory}"], $zipFile->getPath());
                    $process->run();

                    if ( ! $process->isSuccessful()) {
                        throw new \Exception("<error>unzip failed with error: {$process->getOutput()}</error>");
                    }

                    $importResult = $this->importOpenImmoDirectory($importSourceDirectory . $directoryName);
                    if ($importResult) {
                        Files::removeDirectoryRecursively($importSourceDirectory . $directoryName);
                        // delete zip file and unpacked files
                        unlink($zipFile->getRealPath());
                        $this->output->outputLine("<info>deleted {$directoryName} and {$zipFile->getFilename()}</info>");

                    }
                } else {
                    throw new \Exception('<error>unzip not found on this host!</error>');
                }
            }
        } else {
            $this->output->outputLine("No files found.");
        }
    }

    /**
     * @param string $directory
     *
     * @return bool
     * @throws NodeException
     * @throws NodeNotFoundException
     * @throws NodeTypeNotFoundException
     * @throws \ReflectionException
     * @throws \Neos\Eel\Exception
     * @throws \Exception
     */
    protected function importOpenImmoDirectory(string $directory): bool
    {
        $this->output->outputLine("Importing xml and assets from directory {$directory} ...");
        $openImmo = $this->getParsedXml($directory);

        // get all existing nodes before import for comparison
        $existingRealEstateNodes = $this->contentHelper->findNodesByNodeType(self::OPEN_IMMO_DOCUMENT_IMMOBILIE);
        $existingNodeCount       = count($existingRealEstateNodes);
        $this->output->outputLine("<info>Found {$existingNodeCount} nodes in system before import.</info>");

        /* @var Immobilie */
        foreach ($openImmo->getAnbieter()[0]->getImmobilie() as $immobilie) {
            $actionToPerform  = $immobilie->getVerwaltungTechn()->getAktion()->getAktionart();
            $estateIdentifier = $immobilie->getVerwaltungTechn()->getObjektnrIntern();

            /* @var ?NodeInterface $existingNode */
            $existingNode = null;
            /* @var NodeInterface $nodeFromDb */
            foreach ($existingRealEstateNodes as $nodeKey => $nodeFromDb) {
                if ($nodeFromDb->getProperty('estateIdentifier') === $estateIdentifier) {
                    $existingNode = $nodeFromDb;
                    break;
                }
            }

            switch ($actionToPerform) {

                default:
                case Aktion::AKTIONART_CHANGE:
                    if (is_null($existingNode)) {
                        // create
                        /* @var NodeInterface $parentNode */
                        $parentNode = $this->contentHelper->findNode(
                            $this->importConfig['targetNodeType']
                        );
                        if (is_null($parentNode)) {
                            throw new \Exception("<error>No parent node of type {$this->importConfig['targetNodeType']} found!</error>");
                        }

                        $nodeProperties = [
                            'title'            => $this->generateNodeName($immobilie),
                            'estateIdentifier' => $estateIdentifier,
                        ];
                        if ( ! $this->importConfig['showAddressInUrl']) {
                            $nodeProperties['uriPathSegment'] = $estateIdentifier;
                            $nodeProperties['titleOverride']  = $estateIdentifier;
                        }
                        $existingNode = $this->contentHelper->createNodeFromTemplateInParent(
                            self::OPEN_IMMO_DOCUMENT_IMMOBILIE,
                            $parentNode->getNodeAggregateIdentifier(),
                            $nodeProperties,
                            $this->generateNodeName($immobilie)
                        );

                        $this->persistenceManager->persistAll();
                    }

                    $this->updateNodePropertiesAndChildren($existingNode, $immobilie, $directory);
                    /* @var NodeInterface $nodeToHide */
                    foreach ($this->nodesToBeDeactivated as $nodeToHide) {
                        $nodeToHide->setHidden(true);
                    }
                    break;

                case Aktion::AKTIONART_DELETE:
                    if (is_null($existingNode)) {
                        throw new \Exception("<error>No estate item found with identifier {$estateIdentifier}!</error>");
                    }

                    $this->output->outputLine("<info>Removing real estate node {$estateIdentifier}!</info>");
                    $existingNode->remove();
                    break;
            }
        }

        return true;
    }

    /**
     * @param NodeInterface $existingNode NEOS node to set the content for.
     * @param object $estateData Object containing the parsed xml data.
     * @param string $directory Path of the current deflated archive for importing binary assets.
     *
     * @throws NodeNotFoundException
     * @throws NodeException
     * @throws NodeTypeNotFoundException
     * @throws \ReflectionException
     */
    protected function updateNodePropertiesAndChildren(NodeInterface $existingNode, object $estateData, string $directory)
    {
        $reflectionClass  = new \ReflectionClass($estateData);
        $classProperties  = $reflectionClass->getProperties();
        $primaryChildNode = $this->getCollectionChildNode($existingNode);

        // make hidden node visible again and hide children before update process
        $this->nodesToBeDeactivated->remove($existingNode->getNodeAggregateIdentifier()->__toString());

        if (null !== $primaryChildNode) {
            /* @var NodeInterface $anyChildNode */
            foreach ($primaryChildNode->findChildNodes() as $anyChildNode) {
                $this->nodesToBeDeactivated->set($anyChildNode->getNodeAggregateIdentifier()->__toString(), $anyChildNode);
            }
        }

        foreach ($classProperties as $classProperty) {
            $getterName    = 'get' . ucfirst($classProperty->getName());
            $propertyValue = $estateData->{$getterName}();
            if (empty($propertyValue)) {
                $this->output->outputLine("<info>Removing property {$classProperty->getName()} as it is empty in import data.</info>");
                $existingNode->removeProperty($classProperty->getName());
                continue;
            }

            $docBlock = $reflectionClass->getProperty($classProperty->getName())->getDocComment();
            preg_match('/@Type\("(.*)"\)/m', $docBlock, $matches);

            switch ($matches[1]) {

                case 'array<string>':
                    $propertyValue = implode(', ', $propertyValue);
                    $existingNode->setProperty($classProperty->getName(), $propertyValue);
                    $this->output->outputLine("<info>Filling property {$classProperty->getName()} with value \"{$propertyValue}\".</info>");
                    break;

                case 'bool':
                case 'string':
                case 'float':
                case 'int':
                case 'datetime':
                case 'DateTime<\'Y-m-d\'>':
                case 'DateTime<\'Y-m-d\TH:i:s\'>':
                case 'DateTime<\'Y-m-d\TH:i:s\', null, [\'Y-m-d\TH:i:sP\', \'Y-m-d\TH:i:s\']>':
                    $existingNode->setProperty($classProperty->getName(), $propertyValue);
                    if ($propertyValue instanceof \DateTime) {
                        $debugPropertyValue = $propertyValue->format('Y-m-d H:i:s');
                    } else {
                        $debugPropertyValue = $propertyValue;
                    }
                    $this->output->outputLine("<info>Filling property {$classProperty->getName()} with value \"{$debugPropertyValue}\".</info>");
                    break;

                default:
                    if ($classProperty->getName() == 'daten') {
                        $format = $estateData->getFormat();
                        /* @var Daten $propertyValue */
                        if ($format == 'pdf') {
                            // import asset
                            try {
                                $neosAsset = $this->contentHelper->importAsset($directory . DIRECTORY_SEPARATOR . $propertyValue->getPfad());
                                $existingNode->setProperty($classProperty->getName(), $neosAsset);
                                $this->output->outputLine("<info>Imported asset from {$directory}/{$propertyValue->getPfad()}.</info>");
                            } catch (ImportException $e) {
                                $this->output->outputLine("<error>Importing asset {$directory}/{$propertyValue->getPfad()} failed! ({$e->getMessage()})</error>");
                            }
                        } else {
                            // import image
                            try {
                                $neosImage = $this->contentHelper->importImage($directory . DIRECTORY_SEPARATOR . $propertyValue->getPfad());
                                $existingNode->setProperty($classProperty->getName(), $neosImage);
                                $this->output->outputLine("<info>Imported image from {$directory}/{$propertyValue->getPfad()}.</info>");
                            } catch (ImportException $e) {
                                $this->output->outputLine("<error>Importing image {$directory}/{$propertyValue->getPfad()} failed! ({$e->getMessage()})</error>");
                            }
                        }
                    } else {
                        $classPropertyType = NeosAdapterGenerator::getNodeTypeNameFromClassname($matches[1]);
                        if (null == $primaryChildNode) {
                            break;
                        }
                        /* @var TraversableNodes $childNodes */
                        $childNodes      = $primaryChildNode->findChildNodes(new NodeTypeConstraints(false, [$classPropertyType]));
                        $childNodesArray = $childNodes->toArray();
                        $this->output->outputLine("<info>Found {$childNodes->count()} of type {$classPropertyType}</info>");

                        if (is_array($propertyValue)) {
                            if ($childNodes->count() <= count($propertyValue)) {
                                // create more nodes
                                for ($i = $childNodes->count(); $i < count($propertyValue); $i++) {
                                    $childNode         = $this->contentHelper->createNodeFromTemplateInParent(
                                        $classPropertyType,
                                        $primaryChildNode->getNodeAggregateIdentifier()
                                    );
                                    $childNodesArray[] = $childNode;
                                }

                                $this->persistenceManager->persistAll();
                            }

                            foreach ($propertyValue as $index => $singleValue) {
                                $this->updateNodePropertiesAndChildren($childNodesArray[$index], $singleValue, $directory);
                            }
                        } else {
                            if ($childNodes->count() == 0) {
                                $childNodesArray[] = $this->contentHelper->createNodeFromTemplateInParent(
                                    $classPropertyType,
                                    $primaryChildNode->getNodeAggregateIdentifier()
                                );

                                $this->persistenceManager->persistAll();
                            }
                            $this->updateNodePropertiesAndChildren($childNodesArray[0], $propertyValue, $directory);
                        }
                    }
                    break;

            }
        }
    }

    /**
     * @param NodeInterface $node
     *
     * @return ?NodeInterface
     */
    protected function getCollectionChildNode(NodeInterface $node)
    {
        /* @var TraversableNodes $result */
        $result = $node->findChildNodes(new NodeTypeConstraints(false, ['Neos.Neos:ContentCollection']), 1);
        if ($result->count() == 1) {
            return $result->getIterator()->current();
        } else {
            return null;
        }
    }

    /**
     * @param string $directory
     *
     * @return Openimmo
     * @throws \Exception
     */
    protected function getParsedXml(string $directory): ?Openimmo
    {
        // read xml
        $finder = new Finder();
        $finder->files()->name('*.xml')->in($directory);
        if ($finder->hasResults()) {
            $iterator = $finder->getIterator();
            $iterator->rewind();
            $xmlString = $iterator->current()->getContents();
            AnnotationRegistry::registerLoader('class_exists');

            $builder = \JMS\Serializer\SerializerBuilder::create();
            $builder
                ->configureHandlers(function (HandlerRegistryInterface $registry) {
                    $registry->registerSubscribingHandler(new DateTimeHandler());
                });
            $serializer = $builder->build();

            if ($this->importConfig['logLastImportedXml']) {
                $this->logger->info(trim($xmlString));
            }

            /* @var Openimmo $openImmo */
            return $serializer->deserialize($xmlString, Openimmo::class, 'xml');
        } else {
            throw new \Exception("<error>No xml file found in directory {$directory}!</error>");
        }
    }

    /**
     * Generates a readable node name.
     *
     * @param Immobilie $estateObject
     *
     * @return string|null
     */
    protected function generateNodeName(Immobilie $estateObject)
    {
        try {
            return "{$estateObject->getGeo()->getStrasse()} {$estateObject->getGeo()->getHausnummer()}";
        } catch (\Exception $e) {
            return $estateObject->getVerwaltungTechn()->getObjektnrIntern();
        }
    }
}
