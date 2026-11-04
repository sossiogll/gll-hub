<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Source;

/**
 * A link from an entry to another one, filled into a Reference field.
 * By external id within the same source instance, or by content type + slug
 * for anything else (a name the source read from a file, say).
 */
final readonly class ExternalRef
{
    public function __construct(
        public string $field,
        public ?string $externalId = null,
        public ?string $type = null,
        public ?string $slug = null,
    ) {
    }
}
