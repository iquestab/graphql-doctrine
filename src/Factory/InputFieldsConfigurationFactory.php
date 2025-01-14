<?php

declare(strict_types=1);

namespace GraphQL\Doctrine\Factory;

use GraphQL\Doctrine\Annotation\Input;
use GraphQL\Doctrine\DocBlockReader;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * A factory to create a configuration for all setters of an entity.
 */
final class InputFieldsConfigurationFactory extends AbstractFieldsConfigurationFactory
{
    protected function getMethodPattern(): string
    {
        return '~^set[A-Z]~';
    }

    /**
     * Get the entire configuration for a method.
     */
    protected function methodToConfiguration(ReflectionMethod $method): ?array
    {
        // Silently ignore setter with anything than exactly 1 parameter
        $params = $method->getParameters();
        if (count($params) !== 1) {
            return null;
        }
        $param = reset($params);

        // Get a field from annotation, or an empty one
        /** @var Input $field */
        $field = $this->getAnnotationReader()->getMethodAnnotation($method, Input::class) ?? new Input();

        if (!$field->getTypeInstance()) {
            $this->convertTypeDeclarationsToInstances($method, $field);
            $this->completeField($field, $method, $param);
        }

        return $field->toArray();
    }

    /**
     * All its types will be converted from string to real instance of Type.
     */
    private function convertTypeDeclarationsToInstances(ReflectionMethod $method, Input $field): void
    {
        $field->setTypeInstance($this->getTypeFromPhpDeclaration($method->getDeclaringClass(), $field->getType()));
    }

    /**
     * Complete field with info from doc blocks and type hints.
     */
    private function completeField(Input $field, ReflectionMethod $method, ReflectionParameter $param): void
    {
        $fieldName = lcfirst(preg_replace('~^set~', '', $method->getName()));
        if (!$field->getName()) {
            $field->setName($fieldName);
        }

        $docBlock = new DocBlockReader($method);
        if (!$field->getDescription()) {
            $field->setDescription($docBlock->getMethodDescription());
        }

        $this->completeFieldDefaultValue($field, $param, $fieldName);
        $this->completeFieldType($field, $method, $param, $docBlock);
    }

    /**
     * Complete field default value from argument and property.
     */
    private function completeFieldDefaultValue(Input $field, ReflectionParameter $param, string $fieldName): void
    {
        if (!$field->hasDefaultValue() && $param->isDefaultValueAvailable()) {
            $field->setDefaultValue($param->getDefaultValue());
        }

        if (!$field->hasDefaultValue()) {
            $defaultValue = $this->getPropertyDefaultValue($fieldName);
            if ($defaultValue !== null) {
                $field->setDefaultValue($defaultValue);
            }
        }
    }

    /**
     * Complete field type from doc blocks and type hints.
     */
    private function completeFieldType(Input $field, ReflectionMethod $method, ReflectionParameter $param, DocBlockReader $docBlock): void
    {
        // If still no type, look for docBlock
        if (!$field->getTypeInstance()) {
            $typeDeclaration = $docBlock->getParameterType($param);
            $this->throwIfArray($param, $typeDeclaration);
            $field->setTypeInstance($this->getTypeFromPhpDeclaration($method->getDeclaringClass(), $typeDeclaration, true));
        }

        // If still no type, look for type hint
        $type = $param->getType();
        if (!$field->getTypeInstance() && $type instanceof ReflectionNamedType) {
            $this->throwIfArray($param, $type->getName());
            $field->setTypeInstance($this->reflectionTypeToType($type, true));
        }

        $this->nonNullIfHasDefault($field);

        // If still no type, cannot continue
        $this->throwIfNotInputType($param, $field);
    }
}
