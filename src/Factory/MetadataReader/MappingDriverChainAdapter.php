<?php

declare(strict_types=1);

namespace GraphQL\Doctrine\Factory\MetadataReader;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Persistence\Mapping\Driver\AnnotationDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use GraphQL\Doctrine\Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class MappingDriverChainAdapter implements Reader
{
    /**
     * @var MappingDriverChain
     */
    private $chainDriver;

    public function __construct(MappingDriverChain $chainDriver)
    {
        $this->chainDriver = $chainDriver;
    }

    /**
     * Find the reader for the class.
     */
    private function findReader(ReflectionClass $class): Reader
    {
        $className = $class->getName();
        foreach ($this->chainDriver->getDrivers() as $namespace => $driver) {
            if (mb_stripos($className, $namespace) === 0) {
                if ($driver instanceof AnnotationDriver) {
                    return $driver->getReader();
                }
            }
        }

        if ($this->chainDriver->getDefaultDriver() instanceof AnnotationDriver) {
            return $this->chainDriver->getDefaultDriver()->getReader();
        }

        throw new Exception('graphql-doctrine requires ' . $className . ' entity to be configured with a `' . AnnotationDriver::class . '`.');
    }

    public function getClassAnnotations(ReflectionClass $class)
    {
        return $this->findReader($class)
            ->getClassAnnotations($class);
    }

    public function getClassAnnotation(ReflectionClass $class, $annotationName)
    {
        return $this->findReader($class)
            ->getClassAnnotation($class, $annotationName);
    }

    public function getMethodAnnotations(ReflectionMethod $method)
    {
        return $this->findReader($method->getDeclaringClass())
            ->getMethodAnnotations($method);
    }

    public function getMethodAnnotation(ReflectionMethod $method, $annotationName)
    {
        return $this->findReader($method->getDeclaringClass())
            ->getMethodAnnotation($method, $annotationName);
    }

    public function getPropertyAnnotations(ReflectionProperty $property)
    {
        return $this->findReader($property->getDeclaringClass())
            ->getPropertyAnnotations($property);
    }

    public function getPropertyAnnotation(ReflectionProperty $property, $annotationName)
    {
        return $this->findReader($property->getDeclaringClass())
            ->getPropertyAnnotation($property, $annotationName);
    }
}
