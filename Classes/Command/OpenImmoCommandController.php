<?php

namespace Ujamii\OpenImmoNeos\Command;

use gossi\codegen\model\PhpClass;
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
    protected $propertyLinks = [];

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

        $packagePath = $this->packageManager->getPackage('Ujamii.OpenImmo')->getPackagePath();

        $this->outputLine("Found {$numberOfClasses} classes in namespace {$this->openImmoApiNamespace}");
        $this->generateNodeTypeYamlConfig($packagePath);
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
            $yamlProperties  = [];

            /* @var PhpProperty $classProperty */
            foreach ($classProperties as $classProperty) {
                $yamlProperties[$classProperty->getName()] = $this->getPropertyConfig($classProperty);
            }

            $yaml = [
                $nodeType => [
                    'superTypes' => [
                        'Neos.Neos:Document' => true
                    ],
                    'ui'         => [
                        'label' => $documentName,
                        'icon'  => 'icon-house',
                    ],
                    'properties' => $yamlProperties,
                ]
            ];

            $filename = "NodeTypes.Document.{$documentName}.yaml";
            $this->outputLine("Writing {$nodeType} to file {$filename} ...");
            file_put_contents($targetPath . $filename, Yaml::dump($yaml, 10, 2));
        }
    }

    /**
     * @param PhpProperty $property
     *
     * @return array
     */
    protected function getPropertyConfig(PhpProperty $property)
    {
        $typeTags = $property->getDocblock()->getTags('Type');
        if ($typeTags->size() > 0) {
            $typeTag = $typeTags->get(0);
            $typeFromPhpClass    = trim($typeTag->getDescription(), '"() ');
        } else {
            $typeFromPhpClass = trim($property->getType(), '"[] ');
        }

        $additionalConfig = [];
        switch ($typeFromPhpClass) {

            case 'boolean':
            case 'string':
                $neosPropType = $typeFromPhpClass;
                break;

            case 'float':
                $neosPropType = 'string';
                $additionalConfig = [
                    'validation' => [
                        'Neos.Neos/Validation/FloatValidator'
                    ]
                ];
                break;

            case 'int':
                $neosPropType = 'integer';
                break;

            case 'datetime':
            case 'DateTime<\'Y-m-d\'>':
            case 'DateTime<\'Y-m-d\TH:i:s\'>':
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
                $neosPropType = 'references';
                $additionalConfig = [
                    'ui' => [
                        'inspector' => [
                            'editorOptions' => [
                                'nodeTypes' => [$this->getNodeTypeNameFromClassname($typeFromPhpClass)]
                            ]
                        ]
                    ]
                ];
                break;
        }

        $baseConfig = [
            'type' => $neosPropType,
            'ui'   => [
                'label' => $property->getName()
            ],
        ];
        return array_merge($baseConfig, $additionalConfig);
    }

    /**
     * @param string $classname
     *
     * @return string
     */
    protected function getNodeTypeNameFromClassname(string $classname): string
    {
        return "Ujamii.OpenImmo:Document.{$classname}";
    }

}
