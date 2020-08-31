<?php

namespace Ujamii\OpenImmoNeos\Command;

use gossi\codegen\model\PhpClass;
use gossi\codegen\model\PhpConstant;
use gossi\codegen\model\PhpProperty;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Package\PackageManager;
use Symfony\Component\Yaml\Yaml;

/**
 * @Flow\Scope("singleton")
 */
class OpenImmoCommandController extends CommandController
{
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
     * Generates wrapper files for the ujamii/openimmo API.
     *
     * @return int|void|null
     * @throws \Exception
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
            throw new \Exception($msg);
        }

        $packagePath = $this->packageManager->getPackage('Ujamii.OpenImmoNeos')->getPackagePath();

        $this->outputLine("Found {$numberOfClasses} classes in namespace {$this->openImmoApiNamespace}");
        $this->generateNodeTypeYamlConfig($packagePath);

        $this->generateFusionPrototypes($packagePath);
    }

    /**
     * @param string $packagePath
     */
    protected function generateFusionPrototypes(string $packagePath)
    {
        $this->outputLine('Generating fusion prototype files ...');
        $targetPath = $packagePath . implode(DIRECTORY_SEPARATOR, ['Resources', 'Private', 'Fusion', 'NodeTypes']) . DIRECTORY_SEPARATOR;

        foreach ($this->apiClasses as $classname => $file) {
            $documentName     = str_replace($this->openImmoApiNamespace . '\\', '', $classname);
            $nodeType         = $this->getNodeTypeNameFromClassname($documentName);
            $classProperties  = PhpClass::fromFile($file)->getProperties();
            $propertyGetters  = [];
            $propertyRenderer = [];

            /* @var PhpProperty $classProperty */
            foreach ($classProperties as $classProperty) {
                $propertyGetters[] = $this->generateFusionPropertyGetter($classProperty);
                $propertyRenderer[] = $this->generateFusionPropertyRenderer($classProperty);
            }

            // there may also be a contentCollection, so this needs to be rendered, too.
            if ($this->nodeHasChildNodesCache[$nodeType]) {
                $propertyGetters[] = 'mainContent = Neos.Neos:ContentCollection {';
                $propertyGetters[] = '    nodePath = \'main\'';
                $propertyGetters[] = '}';

                $propertyRenderer[] = '{props.mainContent}';
            }

            $propertyGetterCode = implode(PHP_EOL . '    ', array_filter($propertyGetters));
            $rendererCode = implode(PHP_EOL . '        ', array_filter($propertyRenderer));

            $fusionCode = "prototype({$nodeType}) < prototype(Neos.Fusion:Component) {" . PHP_EOL .
                          "    {$propertyGetterCode}" . PHP_EOL .
                          "    renderer = afx`" . PHP_EOL .
                          "        {$rendererCode}" . PHP_EOL .
                          "    `" . PHP_EOL .
                          "}";

            // TODO: add property getter and render component

            $filename = "{$documentName}.fusion";
            $this->outputLine("Writing {$nodeType} to file {$filename} ...");
            file_put_contents($targetPath . $filename, $fusionCode);
        }
    }

    /**
     * Generates fusion code for retrieving property values.
     *
     * @param PhpProperty $property
     *
     * @return string
     */
    protected function generateFusionPropertyGetter(PhpProperty $property): string
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
                $fusionCode = "{$property->getName()} = \${q(node).property('{$property->getName()}')}";
                break;

            default:
                // TODO: fusion code for rendering child node
                $fusionCode = '';
                break;
        }

        return $fusionCode;
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
                $fusionCode = "<Ujamii.OpenImmoNeos:Component.Atom.SimpleProperty name=\"{$property->getName()}\" value={props.{$property->getName()}} />";
                break;

            default:
                // TODO: fusion code for rendering child node
                $fusionCode = '';
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

            // complex types will become NEOS NodeTypes
            $allowedChildNodes = [];

            /* @var PhpProperty $classProperty */
            foreach ($classProperties as $classProperty) {
                $neosPropertyConfig = $this->getPropertyConfig($classProperty, $modelClass);
                if (null !== $neosPropertyConfig) {
                    $yamlProperties[$classProperty->getName()] = $neosPropertyConfig;
                }

                $targetNodeType = $this->getChildNodeType($classProperty);
                if (null !== $targetNodeType) {
                    $allowedChildNodes[$targetNodeType] = true;
                }
            }

            $this->nodeHasChildNodesCache[$nodeType] = false;
            $yaml = [
                $nodeType => [
                    'superTypes' => [
                        'Ujamii.OpenImmo:Mixin.Content.OpenImmoInspector' => true,
                    ],
                    'ui'         => [
                        'label' => ucfirst($documentName),
                        'icon'  => 'icon-sign', // TODO: make the icon configurable in yaml
                    ],
                    'properties' => $yamlProperties,
                ]
            ];

            // only the base type should be shown in the backend
            if ($documentName == 'Immobilie') {
                $yaml[$nodeType]['superTypes']['Neos.Neos:Document'] = true;
                $this->nodeHasChildNodesCache[$nodeType] = true;
            } else {
                $yaml[$nodeType]['superTypes']['Ujamii.OpenImmoNeos:Constraint.Content.Restricted'] = true;
                $yaml[$nodeType]['superTypes']['Neos.Neos:Content']                                 = true;
            }

            if (count($allowedChildNodes) > 0) {
                $allowedChildNodes['*']                               = false;
                $yaml[$nodeType]['childNodes']['main'] = [
                    'type' => 'Neos.Neos:ContentCollection',
                    'constraints' => [
                        'nodeTypes' => $allowedChildNodes
                    ]
                ];
                $this->nodeHasChildNodesCache[$nodeType] = true;
            }

            $filename = "NodeTypes.Document.{$documentName}.yaml";
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
                // those are handled via childNodes
                return null;
                // TODO: add assets here instead of Anhang and Anhaenge
                $neosPropType     = 'references';
                $isPlural         = substr($typeFromPhpClass, 0, 6) == 'array<';
                $singularTypeName = str_replace('array<', '', str_replace('>', '', $typeFromPhpClass));
                $additionalConfig = [
                    'ui' => [
                        'inspector' => [
                            'editorOptions' => [
                                'nodeTypes' => [$this->getNodeTypeNameFromClassname($singularTypeName)]
                            ]
                        ]
                    ],
//                    'validation' => [
//                        'Neos.Neos/Validation/CountValidator' => [
//                            'minimum' => 0,
//                            'maximum' => $isPlural ? 99 : 1,
//                        ]
//                    ]
                ];
                break;
        }

        $baseConfig = [
            'type' => $neosPropType,
            'ui'   => [
                'label'     => ucfirst($property->getName()),
                'inspector' => [
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
        $classname = str_replace($this->openImmoApiNamespace . '\\', '', $classname);
        if ($classname == 'Immobilie') {
            $documentOrContent = 'Document';
        } else {
            $documentOrContent = 'Content';
        }

        return "Ujamii.OpenImmoNeos:{$documentOrContent}.{$classname}";
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

}
