<?php

declare(strict_types=1);

namespace GraphQL\Doctrine\Factory\Type;

use GraphQL\Doctrine\Annotation\Filter;
use GraphQL\Doctrine\Annotation\FilterGroupCondition;
use GraphQL\Doctrine\Annotation\Filters;
use GraphQL\Doctrine\Definition\Operator\AbstractOperator;
use GraphQL\Doctrine\Definition\Operator\BetweenOperatorType;
use GraphQL\Doctrine\Definition\Operator\EmptyOperatorType;
use GraphQL\Doctrine\Definition\Operator\EqualOperatorType;
use GraphQL\Doctrine\Definition\Operator\GreaterOperatorType;
use GraphQL\Doctrine\Definition\Operator\GreaterOrEqualOperatorType;
use GraphQL\Doctrine\Definition\Operator\GroupOperatorType;
use GraphQL\Doctrine\Definition\Operator\HaveOperatorType;
use GraphQL\Doctrine\Definition\Operator\InOperatorType;
use GraphQL\Doctrine\Definition\Operator\LessOperatorType;
use GraphQL\Doctrine\Definition\Operator\LessOrEqualOperatorType;
use GraphQL\Doctrine\Definition\Operator\LikeOperatorType;
use GraphQL\Doctrine\Definition\Operator\NullOperatorType;
use GraphQL\Doctrine\Exception;
use GraphQL\Doctrine\Utils;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\LeafType;
use GraphQL\Type\Definition\Type;
use ReflectionClass;
use ReflectionProperty;

/**
 * A factory to create an InputObjectType from a Doctrine entity to
 * specify condition on fields.
 */
final class FilterGroupConditionTypeFactory extends AbstractTypeFactory
{
    /**
     * @var Filter[][]
     */
    private $customOperators;

    /**
     * Create an InputObjectType from a Doctrine entity to
     * specify condition on fields.
     *
     * @param string $className class name of Doctrine entity
     * @param string $typeName GraphQL type name
     *
     * @return InputObjectType
     */
    public function create(string $className, string $typeName): Type
    {
        $type = new InputObjectType([
            'name' => $typeName,
            'description' => 'Type to specify conditions on fields',
            'fields' => function () use ($className, $typeName): array {
                $filters = [];
                $metadata = $this->entityManager->getClassMetadata($className);

                // Get custom operators
                $this->readCustomOperatorsFromAnnotation($metadata->reflClass);

                // Get all scalar fields
                /** @var array $mapping */
                foreach ($metadata->fieldMappings as $mapping) {
                    $fieldName = $mapping['fieldName'];
                    $property = $metadata->getReflectionProperty($fieldName);

                    // Skip exclusion specified by user
                    if ($this->isPropertyExcluded($property)) {
                        continue;
                    }

                    $leafType = $this->getLeafType($property, $mapping);
                    $operators = $this->getOperators($fieldName, $leafType, false, false);

                    $filters[] = $this->getFieldConfiguration($typeName, $fieldName, $operators);
                }

                // Get all collection fields
                foreach ($metadata->associationMappings as $mapping) {
                    $fieldName = $mapping['fieldName'];
                    $operators = $this->getOperators($fieldName, Type::id(), true, $metadata->isCollectionValuedAssociation($fieldName));

                    $filters[] = $this->getFieldConfiguration($typeName, $fieldName, $operators);
                }

                // Get all custom fields defined by custom operators
                foreach ($this->customOperators as $fieldName => $customOperators) {
                    $operators = [];
                    /** @var Filter $customOperator */
                    foreach ($customOperators as $customOperator) {
                        /** @var LeafType $leafType */
                        $leafType = $this->types->get($customOperator->type);
                        $operators[$customOperator->operator] = $leafType;
                    }

                    $filters[] = $this->getFieldConfiguration($typeName, $fieldName, $operators);
                }

                return $filters;
            },
        ]);

        return $type;
    }

    /**
     * Read the type of the filterGroupCondition, either from Doctrine mapping type, or the override via annotation.
     */
    private function getLeafType(ReflectionProperty $property, array $mapping): LeafType
    {
        if ($mapping['id'] ?? false) {
            return Type::id();
        }

        /** @var null|FilterGroupCondition $filterGroupCondition */
        $filterGroupCondition = $this->getAnnotationReader()->getPropertyAnnotation($property, FilterGroupCondition::class);
        if ($filterGroupCondition) {
            $leafType = $this->getTypeFromPhpDeclaration($property->getDeclaringClass(), $filterGroupCondition->type);

            if ($leafType) {
                if (!$leafType instanceof LeafType) {
                    $propertyFullName = '`' . $property->getDeclaringClass()->getName() . '::$' . $property->getName() . '`';

                    throw new Exception('On property ' . $propertyFullName . ' the annotation `@API\\FilterGroupCondition` expects a, possibly wrapped, `' . LeafType::class . '`, but instead got: ' . get_class($leafType));
                }

                return $leafType;
            }
        }

        /** @var LeafType $leafType */
        $leafType = $this->types->get($mapping['type']);

        return $leafType;
    }

    /**
     * Get the field for conditions on all fields.
     */
    public function getField(string $className): array
    {
        $conditionFieldsType = $this->types->getFilterGroupCondition($className);

        $field = [
            'name' => 'conditions',
            'description' => 'Conditions to be applied on fields',
            'type' => Type::listOf(Type::nonNull($conditionFieldsType)),
        ];

        return $field;
    }

    /**
     * Get the custom operators declared on the class via annotations indexed by their field name.
     */
    private function readCustomOperatorsFromAnnotation(ReflectionClass $class): void
    {
        $allFilters = Utils::getRecursiveClassAnnotations($this->getAnnotationReader(), $class, Filters::class);
        $this->customOperators = [];
        foreach ($allFilters as $classWithAnnotation => $filters) {

            /** @var Filter $filter */
            foreach ($filters->filters as $filter) {
                $className = $filter->operator;
                $this->throwIfInvalidAnnotation($classWithAnnotation, 'Filter', AbstractOperator::class, $className);

                if (!isset($this->customOperators[$filter->field])) {
                    $this->customOperators[$filter->field] = [];
                }
                $this->customOperators[$filter->field][] = $filter;
            }
        }
    }

    /**
     * Get configuration for field.
     *
     * @param LeafType[] $operators
     */
    private function getFieldConfiguration(string $typeName, string $fieldName, array $operators): array
    {
        return [
            'name' => $fieldName,
            'type' => $this->getFieldType($typeName, $fieldName, $operators),
        ];
    }

    /**
     * Return a map of operator class name and their leaf type, including custom operator for the given fieldName.
     *
     * @return LeafType[] indexed by operator class name
     */
    private function getOperators(string $fieldName, LeafType $leafType, bool $isAssociation, bool $isCollection): array
    {
        // For a single pure scalar
        $operatorKeys = [];
        if (!$isAssociation && !$isCollection) {
            array_push($operatorKeys, ...[
                LikeOperatorType::class,
            ]);
        }

        // An association can share some operators independently if it's a single entity or collection of entities
        if ($isAssociation) {
            array_push($operatorKeys, ...[
                HaveOperatorType::class,
                EmptyOperatorType::class,
            ]);
        }

        // We can share most operators for scalar and single entity association
        if (!$isCollection) {
            array_push($operatorKeys, ...[
                BetweenOperatorType::class,
                EqualOperatorType::class,
                GreaterOperatorType::class,
                GreaterOrEqualOperatorType::class,
                InOperatorType::class,
                LessOperatorType::class,
                LessOrEqualOperatorType::class,
                NullOperatorType::class,
                GroupOperatorType::class,
            ]);
        }

        $operators = array_fill_keys($operatorKeys, $leafType);

        // Add custom filters if any
        if (isset($this->customOperators[$fieldName])) {
            foreach ($this->customOperators[$fieldName] as $filter) {
                /** @var LeafType $leafType */
                $leafType = $this->types->get($filter->type);
                $operators[$filter->operator] = $leafType;
            }

            unset($this->customOperators[$fieldName]);
        }

        return $operators;
    }

    /**
     * Get the type for a specific field.
     *
     * @param LeafType[] $operators
     */
    private function getFieldType(string $typeName, string $fieldName, array $operators): InputObjectType
    {
        $fieldType = new InputObjectType([
            'name' => $typeName . ucfirst($fieldName),
            'description' => 'Type to specify a condition on a specific field',
            'fields' => $this->getOperatorConfiguration($operators),
        ]);

        $this->types->registerInstance($fieldType);

        return $fieldType;
    }

    /**
     * Get operators configuration for a specific leaf type.
     *
     * @param LeafType[] $operators
     */
    private function getOperatorConfiguration(array $operators): array
    {
        $conf = [];
        foreach ($operators as $operator => $leafType) {
            $instance = $this->types->getOperator($operator, $leafType);
            $field = [
                'name' => $this->getOperatorFieldName($operator),
                'type' => $instance,
            ];

            $conf[] = $field;
        }

        return $conf;
    }

    /**
     * Get the name for the operator to be used as field name.
     */
    private function getOperatorFieldName(string $className): string
    {
        $name = preg_replace('~OperatorType$~', '', Utils::getTypeName($className));

        return lcfirst($name);
    }
}
