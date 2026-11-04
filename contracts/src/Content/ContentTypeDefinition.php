<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Content;

/**
 * A kind of content: its key and its fields. Title, slug, body, language, author,
 * publication date and thumbnail are the core's own and are not declared here.
 * How a site shows a type, and at which URL, is the site's to decide.
 */
final readonly class ContentTypeDefinition
{
    /**
     * @param string $key stable identifier, lowercase, e.g. "book"
     * @param list<Field> $fields the type's own fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $pluralLabel,
        public array $fields,
    ) {
    }

    public function field(string $key): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }
}
