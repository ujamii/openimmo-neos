<?php

namespace Ujamii\OpenImmo\Command;

use gossi\codegen\model\PhpClass;
use gossi\codegen\model\PhpConstant;
use gossi\codegen\model\PhpProperty;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\ConsoleOutput;
use Neos\Flow\Package\PackageManager;
use Symfony\Component\Yaml\Yaml;

class NeosAdapterGenerator
{

    /**
     * @var string
     */
    public const OPEN_IMMO_API_NAMESPACE = 'Ujamii\\OpenImmo\\API';

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
     * @var ConsoleOutput
     */
    private $output;

    public function __construct(ConsoleOutput $output)
    {
        $this->output = $output;
    }

    public function generateNeosFiles(): void
    {
        $this->output->outputLine('Loading classes ...');
        $classLoader = require FLOW_PATH_ROOT . '/Packages/Libraries/autoload.php';
        $allClasses  = $classLoader->getClassMap();

        foreach ($allClasses as $classname => $file) {
            // Store the namespace of each class in the namespace map
            if (substr($classname, 0, strlen(self::OPEN_IMMO_API_NAMESPACE)) == self::OPEN_IMMO_API_NAMESPACE) {
                $shortname                        = (new \ReflectionClass($classname))->getShortName();
                $this->apiClasses[$classname]     = $file;
                $this->classNamesInApiNamespace[] = $shortname;
            }
        }

        $numberOfClasses = count($this->classNamesInApiNamespace);
        if ($numberOfClasses == 0) {
            $msg = sprintf('No classes in the namepsace "%s" were found! Please call "composer dumpautoload --optimize" to generate a classmap!',
                self::OPEN_IMMO_API_NAMESPACE);
            throw new \Exception($msg);
        }

        $packagePath = $this->packageManager->getPackage('Ujamii.OpenImmo')->getPackagePath();

        $ns = self::OPEN_IMMO_API_NAMESPACE;
        $this->output->outputLine("Found {$numberOfClasses} classes in namespace {$ns}");
        $this->generateNodeTypeYamlConfig($packagePath);
        $this->generateFusionPrototypes($packagePath);
    }

    /**
     * @param string $packagePath
     */
    protected function generateNodeTypeYamlConfig(string $packagePath)
    {
        $this->output->outputLine('Generating yaml config files ...');
        $targetPath = $packagePath . 'Configuration' . DIRECTORY_SEPARATOR;

        foreach ($this->apiClasses as $classname => $file) {
            $documentName    = str_replace(self::OPEN_IMMO_API_NAMESPACE . '\\', '', $classname);
            $nodeType        = self::getNodeTypeNameFromClassname($documentName);
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
            $this->output->outputLine("Writing {$nodeType} to file {$filename} ...");
            file_put_contents($targetPath . $filename, Yaml::dump($yaml, 10, 2));
        }
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

            case 'bool':
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
    protected function generateFusionPrototypes(string $packagePath)
    {
        $this->output->outputLine('Generating fusion prototype files ...');
        $contentTargetPath  = $packagePath . implode(DIRECTORY_SEPARATOR, ['Resources', 'Private', 'Fusion', 'NodeTypes']) . DIRECTORY_SEPARATOR;
        $moleculeTargetPath = $packagePath . implode(DIRECTORY_SEPARATOR, ['Resources', 'Private', 'Fusion', 'Component', 'Molecule']) . DIRECTORY_SEPARATOR;

        foreach ($this->apiClasses as $classname => $file) {
            $documentName       = str_replace(self::OPEN_IMMO_API_NAMESPACE . '\\', '', $classname);
            $nodeType           = self::getNodeTypeNameFromClassname($documentName);
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
            $this->output->outputLine("Writing {$nodeType} to file {$filename} ...");
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

            case 'bool':
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

            case 'bool':
            case 'string':
                $neosPropType = $typeFromPhpClass === 'bool' ? 'boolean' : $typeFromPhpClass;
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
                    $neosPropType     = 'Neos\Media\Domain\Model\Asset';
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

            case 'bool':
            case 'string':
            case 'float':
            case 'int':
            case 'datetime':
            case 'DateTime<\'Y-m-d\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\', null, [\'Y-m-d\TH:i:sP\', \'Y-m-d\TH:i:s\']>':
                // those can be ignored
                return null;

            default:
                $singularTypeName = str_replace('array<', '', str_replace('>', '', $typeFromPhpClass));

                return self::getNodeTypeNameFromClassname($singularTypeName);
        }
    }

    /**
     * @param string $classname May be just the class name or including the namespace.
     *
     * @return string
     */
    public static function getNodeTypeNameFromClassname(string $classname): string
    {
        $classname = str_replace('array<', '', str_replace('>', '', $classname));
        $classname = str_replace(self::OPEN_IMMO_API_NAMESPACE . '\\', '', $classname);
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
