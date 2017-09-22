<?php


declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Factory;

use Doctrine\Common\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Configuration\MetadataConfiguration;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\MappingDriver;
use Doctrine\ORM\Mapping\Factory\Strategy\ConditionalFileWriterClassMetadataGeneratorStrategy;
use Doctrine\ORM\Reflection\ReflectionService;
use Doctrine\ORM\Utility\StaticClassNameConverter;

/**
 * AbstractClassMetadataFactory is the base of ClassMetadata object creation that contain all the metadata mapping
 * information of a class which describes how a class should be mapped to a relational database.
 *
 * @package Doctrine\ORM\Mapping\Factory
 * @since 3.0
 *
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 */
abstract class AbstractClassMetadataFactory implements ClassMetadataFactory
{
    /**
     * Never autogenerate a class metadata and rely that it was generated by some process before deployment.
     *
     * @var int
     */
    const AUTOGENERATE_NEVER = 0;

    /**
     * Always generates a new class metadata in every request. This is only sane during development.
     *
     * @var int
     */
    const AUTOGENERATE_ALWAYS = 1;

    /**
     * Autogenerate the class metadata when the file does not exist.
     * This strategy causes a file exists call whenever any metadata is used the first time in a request.
     *
     * @var int
     */
    const AUTOGENERATE_FILE_NOT_EXISTS = 2;

    /**
     * @var ClassMetadataDefinitionFactory
     */
    protected $definitionFactory;

    /**
     * @var MappingDriver
     */
    protected $mappingDriver;

    /**
     * @var array<string, ClassMetadataDefinition>
     */
    private $definitions = [];

    /**
     * @var array<string, ClassMetadata>
     */
    private $loaded = [];

    /**
     * ClassMetadataFactory constructor.
     *
     * @param MetadataConfiguration $configuration
     */
    public function __construct(MetadataConfiguration $configuration)
    {
        $mappingDriver     = $configuration->getMappingDriver();
        $resolver          = $configuration->getResolver();
        //$autoGenerate      = $configuration->getAutoGenerate();
        $generator         = new ClassMetadataGenerator($mappingDriver);
        $generatorStrategy = new ConditionalFileWriterClassMetadataGeneratorStrategy($generator);
        $definitionFactory = new ClassMetadataDefinitionFactory($resolver, $generatorStrategy);

        $this->mappingDriver     = $mappingDriver;
        $this->definitionFactory = $definitionFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllMetadata()
    {
        $metadata = [];

        foreach ($this->mappingDriver->getAllClassNames() as $className) {
            $metadata[] = $this->getMetadataFor($className);
        }

        return $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadataFor($className)
    {
        $entityClassName = StaticClassNameConverter::getRealClass($className);

        if (isset($this->loaded[$entityClassName])) {
            return $this->loaded[$entityClassName];
        }

        $metadataBuildingContext = new ClassMetadataBuildingContext($this);
        $parentClassNameList     = $this->getParentClassNameList($entityClassName);
        $parentClassNameList[]   = $entityClassName;
        $parent                  = null;

        foreach ($parentClassNameList as $parentClassName) {
            if (isset($this->loaded[$parentClassName])) {
                $parent = $this->loaded[$parentClassName];

                continue;
            }

            $definition = $this->getOrCreateClassMetadataDefinition($parentClassName, $parent);

            $parent = $this->loaded[$parentClassName] = $this->createClassMetadata($definition);
        }

        $metadataBuildingContext->validate();

        return $this->loaded[$entityClassName];
    }

    /**
     * {@inheritdoc}
     */
    public function hasMetadataFor($className)
    {
        return isset($this->loaded[$className]);
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadataFor($className, $class)
    {
        $this->loaded[$className] = $class;
    }

    /**
     * {@inheritdoc}
     */
    public function isTransient($className)
    {
        return $this->mappingDriver->isTransient($className);
    }

    /**
     * @param ClassMetadataDefinition $definition
     *
     * @return ClassMetadata
     */
    protected function createClassMetadata(ClassMetadataDefinition $definition) : ClassMetadata
    {
        /** @var ClassMetadata $classMetadata */
        $metadataFqcn  = $definition->metadataClassName;
        $classMetadata = new $metadataFqcn($definition->parentClassMetadata);

        $classMetadata->wakeupReflection($this->getReflectionService());

        return $classMetadata;
    }

    /**
     * Create a class metadata definition for the given class name.
     *
     * @param string             $className
     * @param ClassMetadata|null $parent
     *
     * @return ClassMetadataDefinition
     */
    private function getOrCreateClassMetadataDefinition(string $className, ?ClassMetadata $parent) : ClassMetadataDefinition
    {
        if (! isset($this->definitions[$className])) {
            $this->definitions[$className] = $this->definitionFactory->build($className, $parent);
        }

        return $this->definitions[$className];
    }

    /**
     * @param string $className
     *
     * @return array
     */
    private function getParentClassNameList(string $className) : array
    {
        $reflectionService   = $this->getReflectionService();
        $parentClassNameList = [];

        foreach (array_reverse($reflectionService->getParentClasses($className)) as $parentClassName) {
            if ($this->mappingDriver->isTransient($parentClassName)) {
                continue;
            }

            $parentClassNameList[] = $parentClassName;
        }

        return $parentClassNameList;
    }

    /**
     * @return ReflectionService
     */
    abstract protected function getReflectionService();
}
