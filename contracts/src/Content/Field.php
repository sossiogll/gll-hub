<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Content;

/**
 * One field of a content type.
 *
 * $curated decides who owns the field when an entry comes from a source:
 * false (the default) = the source owns it, it is read-only in the admin and a sync
 * overwrites it; true = the person owns it, a sync never touches it (order, featured,
 * a shorter claim for cards…).
 *
 * $translatable says whether translations of an entry may differ on it. A curated field that is not translatable
 * has one value for the whole content, whichever language it is edited in.
 */
final readonly class Field
{
    /**
     * @param list<string> $options for Enum: the allowed values
     * @param list<Field> $of for List: the fields of each item; for Group: the fields of its one value
     * @param list<string> $references for Reference: the content type keys it may point to
     */
    public function __construct(
        public string $key,
        public string $label,
        public FieldType $type,
        public bool $required = false,
        public bool $translatable = false,
        public bool $curated = false,
        public bool $multiple = false,
        public string $help = '',
        public array $options = [],
        public array $of = [],
        public array $references = [],
        public ?int $maxLength = null,
    ) {
    }
}
