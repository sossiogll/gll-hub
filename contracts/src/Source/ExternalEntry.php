<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Source;

use DateTimeImmutable;

/**
 * One piece of content as a source sees it.
 *
 * The core's own fields (title, slug, summary, body, language, dates, canonical URL)
 * are properties; the content type's fields go in $fields, keyed by Field::$key.
 * Curated fields are never sent: a source does not know them.
 */
final readonly class ExternalEntry
{
    /**
     * @param string $externalId stable within the source instance (a repo id, a post GUID)
     * @param string $type a content type key listed in SourceDefinition::$produces
     * @param string|null $body in $bodyFormat; the core sanitizes and converts it
     * @param string|null $translationKey entries sharing it are translations of each other
     * @param array<string, mixed> $fields values for the content type's fields
     * @param list<RemoteMedia> $media
     * @param list<ExternalRef> $references
     * @param string|null $fingerprint changes when the content changes (an ETag, a commit SHA, a hash); the core skips unchanged entries
     * @param string|null $refusal set by a source that saw the item but could not read it; the core keeps what it has and reports the reason
     */
    public function __construct(
        public string $externalId,
        public string $type,
        public string $title,
        public ?string $slug = null,
        public ?string $summary = null,
        public ?string $body = null,
        public string $language = 'en',
        public ?string $translationKey = null,
        public array $fields = [],
        public array $media = [],
        public array $references = [],
        public ?DateTimeImmutable $publishedAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?string $canonicalUrl = null,
        public ?string $fingerprint = null,
        public BodyFormat $bodyFormat = BodyFormat::Markdown,
        public ?string $refusal = null,
    ) {
    }
}
