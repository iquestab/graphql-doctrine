<?php

declare(strict_types=1);

namespace GraphQL\Doctrine\Annotation;

use Doctrine\Common\Annotations\Annotation\Attribute;
use Doctrine\Common\Annotations\Annotation\Attributes;

/**
 * Annotation used to override values for an input field in GraphQL.
 *
 * All values are optional and should only be used to override
 * what is declared by the original method.
 *
 * @Annotation
 * @Target({"METHOD"})
 * @Attributes({
 *     @Attribute("name", required=false, type="string"),
 *     @Attribute("type", required=false, type="string"),
 *     @Attribute("description", required=false, type="string"),
 *     @Attribute("defaultValue", required=false, type="mixed"),
 *     @Attribute("method", required=false, type="string"),
 *     @Attribute("updatable", required=false, type="bool")
 * })
 */
final class Input extends AbstractAnnotation
{
    /** @var string */
    public $method;

    public function toArray(): array
    {
        $data = parent::toArray();

        $data['method'] = $this->method;
        $data['updatable'] = $this->updatable ?? true;

        return $data;
    }
}
