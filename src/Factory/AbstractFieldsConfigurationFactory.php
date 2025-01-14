<?php

declare(strict_types=1);

namespace GraphQL\Doctrine\Factory;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ClassMetadata;
use GraphQL\Doctrine\Annotation\AbstractAnnotation;
use GraphQL\Doctrine\Annotation\Exclude;
use GraphQL\Doctrine\Exception;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\WrappingType;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * A factory to create a configuration for all fields of an entity.
 */
abstract class AbstractFieldsConfigurationFactory extends AbstractFactory
{
    /**
     * Doctrine metadata for the entity.
     *
     * @var ClassMetadata
     */
    private $metadata;

    /**
     * The identity field name, eg: "id".
     *
     * @var string
     */
    private $identityField;

    /**
     * Returns the regexp pattern to filter method names.
     */
    abstract protected function getMethodPattern(): string;

    /**
     * Get the entire configuration for a method.
     */
    abstract protected function methodToConfiguration(ReflectionMethod $method): ?array;

    /**
     * Create a configuration for all fields of Doctrine entity.
     */
    public function create(string $className): array
    {
        $this->findIdentityField($className);

        $class = $this->metadata->getReflectionClass();
        $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
        $fieldConfigurations = [];
        foreach ($methods as $method) {
            // Skip non-callable or non-instance
            if ($method->isAbstract() || $method->isStatic()) {
                continue;
            }

            // Skip non-getter methods
            $name = $method->getName();
            if (!preg_match($this->getMethodPattern(), $name)) {
                continue;
            }

            // Skip exclusion specified by user
            if ($this->isExcluded($method)) {
                continue;
            }

            $configuration = $this->methodToConfiguration($method);
            if ($configuration) {
                $fieldConfigurations[] = $configuration;
            }
        }

        return $fieldConfigurations;
    }

    /**
     * Returns whether the getter is excluded.
     */
    private function isExcluded(ReflectionMethod $method): bool
    {
        $exclude = $this->getAnnotationReader()->getMethodAnnotation($method, Exclude::class);

        return $exclude !== null;
    }

    /**
     * Get a GraphQL type instance from PHP type hinted type, possibly looking up the content of collections.
     */
    final protected function getTypeFromReturnTypeHint(ReflectionMethod $method, string $fieldName): ?Type
    {
        $returnType = $method->getReturnType();
        if (!$returnType instanceof ReflectionNamedType) {
            return null;
        }

        $returnTypeName = $returnType->getName();
        if (is_a($returnTypeName, Collection::class, true) || $returnTypeName === 'array') {
            $targetEntity = $this->getTargetEntity($fieldName);
            if (!$targetEntity) {
                throw new Exception('The method ' . $this->getMethodFullName($method) . ' is type hinted with a return type of `' . $returnTypeName . '`, but the entity contained in that collection could not be automatically detected. Either fix the type hint, fix the doctrine mapping, or specify the type with `@API\Field` annotation.');
            }

            $type = Type::listOf(Type::nonNull($this->getTypeFromRegistry($targetEntity, false)));
            if (!$returnType->allowsNull()) {
                $type = Type::nonNull($type);
            }

            return $type;
        }

        return $this->reflectionTypeToType($returnType);
    }

    /**
     * Convert a reflected type to GraphQL Type.
     */
    final protected function reflectionTypeToType(ReflectionNamedType $reflectionType, bool $isEntityId = false): Type
    {
        $name = $reflectionType->getName();
        if ($name === 'self') {
            $name = $this->metadata->name;
        }

        $type = $this->getTypeFromRegistry($name, $isEntityId);
        if (!$reflectionType->allowsNull()) {
            $type = Type::nonNull($type);
        }

        return $type;
    }

    /**
     * Look up which field is the ID.
     */
    private function findIdentityField(string $className): void
    {
        $this->metadata = $this->entityManager->getClassMetadata($className);
        /** @var array $meta */
        foreach ($this->metadata->fieldMappings as $meta) {
            if ($meta['id'] ?? false) {
                $this->identityField = $meta['fieldName'];
            }
        }
    }

    /**
     * Returns the fully qualified method name.
     */
    final protected function getMethodFullName(ReflectionMethod $method): string
    {
        return '`' . $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()`';
    }

    /**
     * Throws exception if type is an array.
     */
    final protected function throwIfArray(ReflectionParameter $param, ?string $type): void
    {
        if ($type === 'array') {
            throw new Exception('The parameter `$' . $param->getName() . '` on method ' . $this->getMethodFullName($param->getDeclaringFunction()) . ' is type hinted as `array` and is not overridden via `@API\Argument` annotation. Either change the type hint or specify the type with `@API\Argument` annotation.');
        }
    }

    /**
     * Returns whether the given field name is the identity for the entity.
     */
    final protected function isIdentityField(string $fieldName): bool
    {
        return $this->identityField === $fieldName;
    }

    /**
     * Finds the target entity in the given association.
     */
    private function getTargetEntity(string $fieldName): ?string
    {
        return $this->metadata->associationMappings[$fieldName]['targetEntity'] ?? null;
    }

    /**
     * Return the default value, if any, of the property for the current entity.
     *
     * It does take into account that the property might be defined on a parent class
     * of entity. And it will find it if that is the case.
     *
     * @return mixed
     */
    final protected function getPropertyDefaultValue(string $fieldName)
    {
        /** @var null|ReflectionProperty $property */
        $property = $this->metadata->getReflectionProperties()[$fieldName] ?? null;
        if (!$property) {
            return null;
        }

        return $property->getDeclaringClass()->getDefaultProperties()[$fieldName] ?? null;
    }

    /**
     * Input with default values cannot be non-null.
     */
    final protected function nonNullIfHasDefault(AbstractAnnotation $annotation): void
    {
        $type = $annotation->getTypeInstance();
        if ($type instanceof NonNull && $annotation->hasDefaultValue()) {
            $annotation->setTypeInstance($type->getWrappedType());
        }
    }

    /**
     * Throws exception if argument type is invalid.
     */
    final protected function throwIfNotInputType(ReflectionParameter $param, AbstractAnnotation $annotation): void
    {
        $type = $annotation->getTypeInstance();
        $class = new ReflectionClass($annotation);
        $annotationName = $class->getShortName();

        if (!$type) {
            throw new Exception('Could not find type for parameter `$' . $param->name . '` for method ' . $this->getMethodFullName($param->getDeclaringFunction()) . '. Either type hint the parameter, or specify the type with `@API\\' . $annotationName . '` annotation.');
        }

        if ($type instanceof WrappingType) {
            $type = $type->getWrappedType(true);
        }

        if (!($type instanceof InputType)) {
            throw new Exception('Type for parameter `$' . $param->name . '` for method ' . $this->getMethodFullName($param->getDeclaringFunction()) . ' must be an instance of `' . InputType::class . '`, but was `' . get_class($type) . '`. Use `@API\\' . $annotationName . '` annotation to specify a custom InputType.');
        }
    }
}
