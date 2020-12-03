<?php

namespace Ujamii\OpenImmo\Command;

use Doctrine\Common\Annotations\AnnotationRegistry;
use gossi\codegen\model\PhpClass;
use gossi\codegen\model\PhpConstant;
use gossi\codegen\model\PhpProperty;
use JMS\Serializer\Handler\HandlerRegistryInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\NodeType\NodeTypeConstraints;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodes;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Controller\Exception\NodeNotFoundException;
use Neos\Utility\Files;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Ujamii\OpenImmo\API\Aktion;
use Ujamii\OpenImmo\API\Daten;
use Ujamii\OpenImmo\API\Immobilie;
use Ujamii\OpenImmo\API\Openimmo;
use Ujamii\OpenImmo\Handler\DateTimeHandler;
use Ujamii\OpenImmo\Service\ContentHelper;

/**
 * @Flow\Scope("singleton")
 */
class OpenImmoCommandController extends CommandController
{
    public const OPEN_IMMO_DOCUMENT_IMMOBILIE = 'Ujamii.OpenImmo:Document.Immobilie';

    /**
     * @var string
     */
    protected $openImmoApiNamespace = 'Ujamii\OpenImmo\API';

    /**
     * List of API classes incl. namespace.
     *
     * @var array
     */
    protected $apiClasses = [];

    /**
     * List of API classes excl. namepsace.
     *
     * @var array
     */
    protected $classNamesInApiNamespace = [];

    /**
     * @var array
     */
    protected $nodeHasChildNodesCache = [];

    /**
     * @var PackageManager
     * @Flow\Inject
     */
    protected $packageManager;

    /**
     * @Flow\InjectConfiguration(path="nodeTypeIcons")
     * @var array
     */
    protected $nodeTypeIcons;

    /**
     * @Flow\InjectConfiguration(path="nodeTypeLabels")
     * @var array
     */
    protected $nodeTypeLabels;

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
     * Generates wrapper files (yaml and fusion) for the ujamii/openimmo API.
     *
     * @return int|void|null
     * @throws \Neos\Flow\Package\Exception\UnknownPackageException
     * @throws \ReflectionException
     */
    public function generateCommand()
    {
        $this->outputLine('Loading classes ...');
        $classLoader = require FLOW_PATH_ROOT . '/Packages/Libraries/autoload.php';
        $allClasses  = $classLoader->getClassMap();

        foreach ($allClasses as $classname => $file) {
            // Store the namespace of each class in the namespace map
            if (substr($classname, 0, strlen($this->openImmoApiNamespace)) == $this->openImmoApiNamespace) {
                $shortname                        = (new \ReflectionClass($classname))->getShortName();
                $this->apiClasses[$classname]     = $file;
                $this->classNamesInApiNamespace[] = $shortname;
            }
        }

        $numberOfClasses = count($this->classNamesInApiNamespace);
        if ($numberOfClasses == 0) {
            $msg = sprintf('No classes in the namepsace "%s" were found! Please call "composer dumpautoload --optimize" to generate a classmap!',
                $this->openImmoApiNamespace);
            $this->outputLine("<error>$msg</error>");
            $this->sendAndExit(1);
        }

        $packagePath = $this->packageManager->getPackage('Ujamii.OpenImmo')->getPackagePath();

        $this->outputLine("Found {$numberOfClasses} classes in namespace {$this->openImmoApiNamespace}");
        $this->generateNodeTypeYamlConfig($packagePath);
        $this->generateFusionPrototypes($packagePath);
    }

    /**
     * Imports OpenImmo data from configured directory.
     * @throws NodeNotFoundException
     * @throws \Neos\ContentRepository\Exception\ImportException
     * @throws \Neos\ContentRepository\Exception\NodeTypeNotFoundException
     * @throws \Neos\Eel\Exception
     * @throws \ReflectionException
     */
    public function importCommand()
    {
        $importSourceDirectory = $this->importConfig['sourceDirectory'];
        $this->outputLine("Importing ZIP files from directory {$importSourceDirectory} ...");

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
                        $this->outputLine("<info>directory {$directoryName} already exists, so delete it!</info>");
                        Files::removeDirectoryRecursively($importSourceDirectory . $directoryName);
                    }
                    $this->outputLine("<info>extracting {$zipFile->getRealPath()} to {$absoluteTargetDirectory}</info>");
                    $process = new Process(['unzip', $zipFile->getRealPath(), "-d{$absoluteTargetDirectory}"], $zipFile->getPath());
                    $process->run();

                    if ( ! $process->isSuccessful()) {
                        $this->outputLine('<error>unzip failed with error:</error>');
                        $this->output($process->getOutput());
                        $this->sendAndExit(1);
                    }

                    $importResult = $this->importOpenImmoDirectory($importSourceDirectory . $directoryName);
                    if ($importResult) {
                        Files::removeDirectoryRecursively($importSourceDirectory . $directoryName);
                        // delete zip file and unpacked files
                        unlink($zipFile->getRealPath());
                        $this->outputLine("<info>deleted {$directoryName} and {$zipFile->getFilename()}</info>");

                    }
                } else {
                    $this->outputLine('<error>unzip not found on this host!</error>');
                    $this->sendAndExit(1);
                }
            }
        } else {
            $this->outputLine("No files found.");
        }
    }

    /**
     * @param string $directory
     *
     * @return bool
     * @throws NodeNotFoundException
     * @throws \Neos\ContentRepository\Exception\ImportException
     * @throws \Neos\ContentRepository\Exception\NodeTypeNotFoundException
     * @throws \Neos\Eel\Exception
     * @throws \ReflectionException
     */
    protected function importOpenImmoDirectory(string $directory): bool
    {
        $this->outputLine("Importing xml and assets from directory {$directory} ...");
        $openImmo = $this->getParsedXml($directory);

        // get all existing nodes before import for comparison
        $existingRealEstateNodes = $this->contentHelper->findNodesByNodeType(self::OPEN_IMMO_DOCUMENT_IMMOBILIE);
        $existingNodeCount       = count($existingRealEstateNodes);
        $this->outputLine("<info>Found {$existingNodeCount} nodes in system before import.</info>");

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
                    unset($existingRealEstateNodes[$nodeKey]);
                    break;
                }
            }

            switch ($actionToPerform) {

                case Aktion::AKTIONART_CHANGE:
                    if (is_null($existingNode)) {
                        // create
                        /* @var NodeInterface $parentNode */
                        $parentNode = $this->contentHelper->findNode(
                            $this->importConfig['targetNodeType']
                        );
                        if (is_null($parentNode)) {
                            $this->outputLine("<error>No parent node of type {$this->importConfig['targetNodeType']} found!</error>");
                            $this->sendAndExit(1);

                            return false;
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
                    break;

                case Aktion::AKTIONART_DELETE:
                    if (is_null($existingNode)) {
                        $this->outputLine("<error>No estate item found with identifier {$estateIdentifier}!</error>");
                        $this->sendAndExit(1);

                        return false;
                    }

                    $this->outputLine("<info>Removing real estate node {$estateIdentifier}!</info>");
                    $existingNode->remove();
                    break;
            }
        }

        $deactivateNodeCount = count($existingRealEstateNodes);
        $this->outputLine("<info>deactivating {$deactivateNodeCount} existing nodes which were not included in the import:</info>");
        /* @var NodeInterface $nodesToDeactivate */
        foreach ($existingRealEstateNodes as $nodesToDeactivate) {
            $nodesToDeactivate->setHidden(true);
            $this->outputLine("<info>deactivating object '{$nodesToDeactivate->getProperty('estateIdentifier')}'.</info>");
        }

        return true;
    }

    /**
     * @param NodeInterface $existingNode NEOS node to set the content for.
     * @param object $estateData Object containing the parsed xml data.
     * @param string $directory Path of the current deflated archive for importing binary assets.
     *
     * @throws NodeNotFoundException
     * @throws \Neos\ContentRepository\Exception\ImportException
     * @throws \Neos\ContentRepository\Exception\NodeTypeNotFoundException
     * @throws \ReflectionException
     */
    protected function updateNodePropertiesAndChildren(NodeInterface $existingNode, object $estateData, string $directory)
    {
        $reflectionClass       = new \ReflectionClass($estateData);
        $classProperties       = $reflectionClass->getProperties();

        foreach ($classProperties as $classProperty) {
            $getterName    = 'get' . ucfirst($classProperty->getName());
            $propertyValue = $estateData->{$getterName}();
            if (empty($propertyValue)) {
                $this->outputLine("<info>Removing property {$classProperty->getName()} as it is empty in import data.</info>");
                $existingNode->removeProperty($classProperty->getName());
                continue;
            }

            $docBlock = $reflectionClass->getProperty($classProperty->getName())->getDocComment();
            preg_match('/@Type\("(.*)"\)/m', $docBlock, $matches);

            switch ($matches[1]) {

                case 'array<string>':
                    $propertyValue = implode(', ', $propertyValue);
                    $existingNode->setProperty($classProperty->getName(), $propertyValue);
                    $this->outputLine("<info>Filling property {$classProperty->getName()} with value \"{$propertyValue}\".</info>");
                    break;

                case 'boolean':
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
                    $this->outputLine("<info>Filling property {$classProperty->getName()} with value \"{$debugPropertyValue}\".</info>");
                    break;

                default:
                    if ($classProperty->getName() == 'daten') {
                        // import asset
                        /* @var Daten $propertyValue */
                        $neosImage = $this->contentHelper->importImage($directory . DIRECTORY_SEPARATOR . $propertyValue->getPfad());
                        $existingNode->setProperty($classProperty->getName(), $neosImage);
                        $this->outputLine("<info>Imported image from {$directory}/{$propertyValue->getPfad()}.</info>");
                    } else {
                        $classPropertyType = $this->getNodeTypeNameFromClassname($matches[1]);
                        $primaryChildNode  = $this->getCollectionChildNode($existingNode);
                        if (null == $primaryChildNode) {
                            break;
                        }
                        /* @var TraversableNodes $childNodes */
                        $childNodes      = $primaryChildNode->findChildNodes(new NodeTypeConstraints(false, [$classPropertyType]));
                        $childNodesArray = $childNodes->toArray();
                        $this->outputLine("<info>Found {$childNodes->count()} of type {$classPropertyType}</info>");

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

            /* @var Openimmo $openImmo */
            return $serializer->deserialize($xmlString, Openimmo::class, 'xml');
        } else {
            $this->outputLine("<error>No xml file found in directory {$directory}!</error>");
            $this->sendAndExit(1);
        }

        return null;
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

    /**
     * @param string $packagePath
     */
    protected function generateFusionPrototypes(string $packagePath)
    {
        $this->outputLine('Generating fusion prototype files ...');
        $contentTargetPath  = $packagePath . implode(DIRECTORY_SEPARATOR, ['Resources', 'Private', 'Fusion', 'NodeTypes']) . DIRECTORY_SEPARATOR;
        $moleculeTargetPath = $packagePath . implode(DIRECTORY_SEPARATOR, ['Resources', 'Private', 'Fusion', 'Component', 'Molecule']) . DIRECTORY_SEPARATOR;

        foreach ($this->apiClasses as $classname => $file) {
            $documentName       = str_replace($this->openImmoApiNamespace . '\\', '', $classname);
            $nodeType           = $this->getNodeTypeNameFromClassname($documentName);
            $classProperties    = PhpClass::fromFile($file)->getProperties();
            $propertyGetters    = [];
            $propertyRenderer   = [];
            $moleculeProperties = [];

            /* @var PhpProperty $classProperty */
            foreach ($classProperties as $classProperty) {
                list($propertyGetter, $moleculeProperty) = $this->generateFusionPropertyGetter($classProperty);
                $propertyGetters[]    = $propertyGetter;
                $moleculeProperties[] = $moleculeProperty;
                $propertyRenderer[]   = $this->generateFusionPropertyRenderer($classProperty);
            }

            // there may also be a contentCollection, so this needs to be rendered, too.
            if ($this->nodeHasChildNodesCache[$nodeType]) {
                $propertyGetters[] = '@context.mainContent = Neos.Neos:ContentCollection {';
                $propertyGetters[] = '    nodePath = \'main\'';
                $propertyGetters[] = '}';

                $propertyRenderer[] = '{props.mainContent}';
            }

            $propertyGetterCode   = implode(PHP_EOL . '    ', array_filter($propertyGetters));
            $moleculePropertyCode = implode(PHP_EOL . '    ', array_filter($moleculeProperties));
            $rendererCode         = implode(PHP_EOL . '        ', array_filter($propertyRenderer));
            $moleculeName         = "Ujamii.OpenImmo:Component.Molecule.{$documentName}";

            $fusionCode = "prototype({$nodeType}) < prototype(Neos.Fusion:Component) {" . PHP_EOL .
                          "    {$propertyGetterCode}" . PHP_EOL .
                          "    renderer = afx`" . PHP_EOL .
                          "        <{$moleculeName} {...props}";
            if ($this->nodeHasChildNodesCache[$nodeType]) {
                $fusionCode .= " mainContent={mainContent}";
            }
            $fusionCode .= "/>" . PHP_EOL .
                           "    `" . PHP_EOL .
                           "}";

            $moleculeCode = "prototype(Ujamii.OpenImmo:Component.Molecule.{$documentName}) < prototype(Neos.Fusion:Component) {" . PHP_EOL .
                            "    {$moleculePropertyCode}" . PHP_EOL .
                            "    renderer = afx`" . PHP_EOL .
                            "        {$rendererCode}" . PHP_EOL .
                            "    `" . PHP_EOL .
                            "}";

            $filename = "{$documentName}.fusion";
            $this->outputLine("Writing {$nodeType} to file {$filename} ...");
            file_put_contents($contentTargetPath . $filename, $fusionCode);
            file_put_contents($moleculeTargetPath . $filename, $moleculeCode);
        }
    }

    /**
     * Generates fusion code for retrieving property values.
     *
     * @param PhpProperty $property
     *
     * @return array [fusionCodeForGetter, fusionCodeForMolecule]
     */
    protected function generateFusionPropertyGetter(PhpProperty $property): array
    {
        $typeFromPhpClass = $this->getPhpPropertyType($property);

        switch ($typeFromPhpClass) {

            case 'boolean':
            case 'string':
            case 'float':
            case 'int':
            case 'datetime':
            case 'DateTime<\'Y-m-d\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\', null, [\'Y-m-d\TH:i:sP\', \'Y-m-d\TH:i:s\']>':
                $fusionGetter             = "{$property->getName()} = \${q(node).property('{$property->getName()}')}";
                $fusionMoleculeDefinition = "{$property->getName()} = null";
                break;

            default:
                if ($property->getName() == 'daten') {
                    // special case for assets
                    $fusionGetter             = "{$property->getName()} = \${q(node).property('{$property->getName()}')}";
                    $fusionMoleculeDefinition = "{$property->getName()} = null";
                } else {
                    // fusion code for retrieving child node(s) is added only once (@see self::generateFusionPrototypes)
                    $fusionGetter             = '';
                    $fusionMoleculeDefinition = '';
                }
                break;
        }

        return [$fusionGetter, $fusionMoleculeDefinition];
    }

    /**
     * Generates fusion code for rendering.
     *
     * @param PhpProperty $property
     *
     * @return string
     */
    protected function generateFusionPropertyRenderer(PhpProperty $property): string
    {
        $typeFromPhpClass = $this->getPhpPropertyType($property);

        switch ($typeFromPhpClass) {

            case 'boolean':
            case 'string':
            case 'float':
            case 'int':
            case 'datetime':
            case 'DateTime<\'Y-m-d\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\', null, [\'Y-m-d\TH:i:sP\', \'Y-m-d\TH:i:s\']>':
                $fusionCode = "<Ujamii.OpenImmo:Component.Atom.SimpleProperty name=\"{$property->getName()}\" value={props.{$property->getName()}} />";
                break;

            default:
                if ($property->getName() == 'daten') {
                    // special case for assets
                    $fusionCode = "<Neos.Neos:ImageTag asset={props.{$property->getName()}} />";
                } else {
                    // fusion code for rendering child node(s) is added only once (@see self::generateFusionPrototypes)
                    $fusionCode = '';
                }

                break;
        }

        return $fusionCode;
    }

    /**
     * @param string $packagePath
     */
    protected function generateNodeTypeYamlConfig(string $packagePath)
    {
        $this->outputLine('Generating yaml config files ...');
        $targetPath = $packagePath . 'Configuration' . DIRECTORY_SEPARATOR;

        foreach ($this->apiClasses as $classname => $file) {
            $documentName    = str_replace($this->openImmoApiNamespace . '\\', '', $classname);
            $nodeType        = $this->getNodeTypeNameFromClassname($documentName);
            $modelClass      = PhpClass::fromFile($file);
            $classProperties = $modelClass->getProperties();

            // simple types like string and int will become properties
            $yamlProperties = [];

            if ($documentName == 'Immobilie') {
                $yamlProperties['estateIdentifier'] = [
                    'type' => 'string',
                    'ui'   => [
                        'label'     => 'estate identifier',
                        'inspector' => [
                            'group' => 'openimmo',
                        ]
                    ],
                ];
            }

            // complex types will become NEOS NodeTypes
            $allowedChildNodes = [];

            /* @var PhpProperty $classProperty */
            foreach ($classProperties as $classProperty) {
                $neosPropertyConfig = $this->getPropertyConfig($classProperty, $modelClass);

                if (null !== $neosPropertyConfig) {
                    $yamlProperties[$classProperty->getName()] = $neosPropertyConfig;
                }

                $targetNodeType = $this->getChildNodeType($classProperty);
                if ( ! in_array($targetNodeType, [null, 'Ujamii.OpenImmo:Content.Daten', 'Ujamii.OpenImmo:Content.string'])) {
                    // the NodeType Ujamii.OpenImmo:Content.Daten is special, as this will be converted to
                    // a NEOS CMS asset instead of a string based property.

                    // string type is added as text property instead of child node
                    $allowedChildNodes[$targetNodeType] = true;
                }
            }

            $this->nodeHasChildNodesCache[$nodeType] = false;
            $yaml                                    = [];
            if (isset($this->nodeTypeLabels[ucfirst($documentName)])) {
                $yaml[$nodeType]['label'] = $this->nodeTypeLabels[ucfirst($documentName)];
            }
            $yaml = array_merge_recursive($yaml, [
                $nodeType => [
                    'superTypes' => [
                        'Ujamii.OpenImmo:Mixin.Content.OpenImmoInspector' => true,
                    ],
                    'ui'         => [
                        'label' => ucfirst($documentName),
                        'icon'  => $this->getIconForNodeType(ucfirst($documentName)),
                    ],
                    'properties' => $yamlProperties,
                ]
            ]);

            // only the base type should be shown in the backend
            if ($documentName == 'Immobilie') {
                $documentType                            = 'Document';
                $this->nodeHasChildNodesCache[$nodeType] = true;
            } else {
                $documentType                                                                   = 'Content';
                $yaml[$nodeType]['superTypes']['Ujamii.OpenImmo:Constraint.Content.Restricted'] = true;
            }
            $yaml[$nodeType]['superTypes']['Neos.Neos:' . $documentType] = true;

            if (count($allowedChildNodes) > 0 && $nodeType != 'Ujamii.OpenImmo:Content.Anhang') {
                $allowedChildNodes['*']                  = false;
                $yaml[$nodeType]['childNodes']['main']   = [
                    'type'        => 'Neos.Neos:ContentCollection',
                    'constraints' => [
                        'nodeTypes' => $allowedChildNodes
                    ]
                ];
                $this->nodeHasChildNodesCache[$nodeType] = true;
            }

            $filename = "NodeTypes.{$documentType}.{$documentName}.yaml";
            $this->outputLine("Writing {$nodeType} to file {$filename} ...");
            file_put_contents($targetPath . $filename, Yaml::dump($yaml, 10, 2));
        }
    }

    /**
     * @param PhpProperty $property
     *
     * @param PhpClass $class
     *
     * @return array
     */
    protected function getPropertyConfig(PhpProperty $property, PhpClass $class): ?array
    {
        $typeFromPhpClass = $this->getPhpPropertyType($property);

        $additionalConfig = [];
        switch ($typeFromPhpClass) {

            case 'boolean':
            case 'string':
                $neosPropType = $typeFromPhpClass;
                if ($property->getDocblock()->hasTag('see')) {
                    // there may also be constants with that name, then generate a fixed selectbox
                    $items = [];
                    /* @var $constant PhpConstant */
                    foreach ($class->getConstants() as $constant) {
                        $constantFilter = preg_replace('%@see ([A-Z_]+)\*.*%', '$1', $property->getDocblock()->getTags('see')->get(0));
                        if (strpos($constant->getName(), $constantFilter) === 0) {
                            $items[$constant->getValue()] = [
                                'label' => ucfirst(strtolower($constant->getValue()))
                            ];
                        }
                    }
                    $additionalConfig = [
                        'ui' => [
                            'inspector' => [
                                'editor'        => 'Neos.Neos/Inspector/Editors/SelectBoxEditor',
                                'editorOptions' => [
                                    'values' => $items
                                ]
                            ]
                        ]
                    ];
                }
                break;

            case 'array<string>':
                $neosPropType = 'string';
                break;

            case 'float':
                $neosPropType = 'string';
//                $additionalConfig = [
//                    'validation' => [
//                        'Neos.Neos/Validation/FloatValidator'
//                    ]
//                ];
                break;

            case 'int':
                $neosPropType = 'integer';
                break;

            case 'datetime':
            case 'DateTime<\'Y-m-d\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\', null, [\'Y-m-d\TH:i:sP\', \'Y-m-d\TH:i:s\']>':
                $neosPropType = 'DateTime';
                if ($typeFromPhpClass == 'DateTime<\'Y-m-d\'>') {
                    $additionalConfig = [
                        'ui' => [
                            'inspector' => [
                                'editorOptions' => [
                                    'format' => 'd.m.Y'
                                ]
                            ]
                        ]
                    ];
                }
                break;

            default:
                if ($property->getName() == 'daten') {
                    // special case for assets
                    $neosPropType     = 'Neos\Media\Domain\Model\ImageInterface';
                    $additionalConfig = [
                        'ui' => [
                            'inspector' => [
                                'editorOptions' => [
                                    'features' => [
                                        'crop'   => true,
                                        'resize' => true,
                                    ]
                                ]
                            ]
                        ],
                    ];
                } else {
                    // those are handled via childNodes
                    return null;
                }
                break;
        }

        $baseConfig = [
            'type' => $neosPropType,
            'ui'   => [
                'label'           => ucfirst($property->getName()),
                'reloadIfChanged' => true,
                'inspector'       => [
                    'group' => 'openimmo'
                ]
            ],
        ];

        return array_merge_recursive($baseConfig, $additionalConfig);
    }

    /**
     * @param PhpProperty $property
     *
     * @return string|null
     */
    protected function getChildNodeType(PhpProperty $property): ?string
    {
        $typeFromPhpClass = $this->getPhpPropertyType($property);

        switch ($typeFromPhpClass) {

            case 'boolean':
            case 'string':
            case 'float':
            case 'int':
            case 'datetime':
            case 'DateTime<\'Y-m-d\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\', null, [\'Y-m-d\TH:i:sP\', \'Y-m-d\TH:i:s\']>':
                // those can be ignored
                return null;
                break;

            default:
                $singularTypeName = str_replace('array<', '', str_replace('>', '', $typeFromPhpClass));

                return $this->getNodeTypeNameFromClassname($singularTypeName);
                break;
        }
    }

    /**
     * @param string $classname May be just the class name or including the namespace.
     *
     * @return string
     */
    protected function getNodeTypeNameFromClassname(string $classname): string
    {
        $classname = str_replace('array<', '', str_replace('>', '', $classname));
        $classname = str_replace($this->openImmoApiNamespace . '\\', '', $classname);
        if ($classname == 'Immobilie') {
            $documentOrContent = 'Document';
        } else {
            $documentOrContent = 'Content';
        }

        return "Ujamii.OpenImmo:{$documentOrContent}.{$classname}";
    }

    /**
     * @param PhpProperty $property
     *
     * @return string
     */
    protected function getPhpPropertyType(PhpProperty $property): string
    {
        $typeTags = $property->getDocblock()->getTags('Type');
        if ($typeTags->size() > 0) {
            $typeTag          = $typeTags->get(0);
            $typeFromPhpClass = trim($typeTag->getDescription(), '"() ');
        } else {
            $typeFromPhpClass = trim($property->getType(), '"[] ');
        }

        return $typeFromPhpClass;
    }

    /**
     * Returns icon identifier for given node type.
     *
     * @param string $nodeType
     *
     * @return string
     */
    protected function getIconForNodeType(string $nodeType): string
    {
        if (isset($this->nodeTypeIcons[$nodeType])) {
            return $this->nodeTypeIcons[$nodeType];
        }

        return $this->nodeTypeIcons['default'];
    }

}
