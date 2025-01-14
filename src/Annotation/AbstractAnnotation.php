<?php

declare(strict_types=1);

namespace GraphQL\Doctrine\Annotation;

use GraphQL\Type\Definition\Type;

/**
 * Abstract annotation with common logic for Argument and Field.
 */
abstract class AbstractAnnotation
{
    /**
     * The name of the argument, it must matches the actual PHP argument name.
     *
     * @var null|string
     * @Required
     */
    private $name;

    /**
     * FQCN of PHP class implementing the GraphQL type.
     *
     * @var null|string
     */
    private $type;

    /**
     * Instance of the GraphQL type.
     *
     * @var null|Type
     */
    private $typeInstance;

    /**
     * @var null|string
     */
    private $description;

    /**
     * @var mixed
     */
    private $defaultValue;

    /**
     * @var bool
     */
    private $hasDefaultValue = false;

    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            $setter = 'set' . ucfirst($key);
            $this->$setter($value);
        }
    }

    public function toArray(): array
    {
        $data = [
            'name' => $this->getName(),
            'type' => $this->getTypeInstance(),
            'description' => $this->getDescription(),
        ];

        if ($this->hasDefaultValue()) {
            $data['defaultValue'] = $this->getDefaultValue();
        }

        return $data;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function hasDefaultValue(): bool
    {
        return $this->hasDefaultValue;
    }

    /**
     * @return mixed
     */
    public function getDefaultValue()
    {
        return $this->defaultValue;
    }

    /**
     * @param mixed $defaultValue
     */
    public function setDefaultValue($defaultValue): void
    {
        $this->defaultValue = $defaultValue;
        $this->hasDefaultValue = true;
    }

    public function getTypeInstance(): ?Type
    {
        return $this->typeInstance;
    }

    public function setTypeInstance(?Type $typeInstance): void
    {
        $this->typeInstance = $typeInstance;
    }
}
