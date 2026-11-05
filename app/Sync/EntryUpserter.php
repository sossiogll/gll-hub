<?php

declare(strict_types=1);

namespace App\Sync;

use App\Content\EntryWriter;
use App\Content\ImportRemoteMedia;
use App\Content\ReferenceStore;
use App\Content\RemoteMediaPruner;
use App\Content\Rules\BodySanitizer;
use App\Content\Rules\Slug;
use App\Content\SharedCuration;
use App\Content\WriteOutcome;
use App\Models\Entry;
use App\Models\SourceInstance;
use App\Packages\PackageRegistry;
use Gll\Hub\Contracts\Content\EntryRules;
use Gll\Hub\Contracts\Content\FieldType;
use Gll\Hub\Contracts\Source\BodyFormat;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Source\SourceDefinition;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * The one way an entry from a source enters the store. An entry that breaks the
 * contract's rules is skipped and reported, never fatal to the run.
 */
final class EntryUpserter
{
    public function __construct(
        private readonly PackageRegistry $registry,
        private readonly EntryWriter $writer,
        private readonly ReferenceStore $references,
        private readonly BodySanitizer $sanitizer,
        private readonly SharedCuration $curation,
        private readonly RemoteMediaPruner $pruner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(SourceInstance $instance, SourceDefinition $source, ExternalEntry $entry, SyncReport $report): void
    {
        $violations = EntryRules::violations($source, array_values($this->registry->contentTypes()), $entry);
        if ($violations !== []) {
            $report->skipped($entry->externalId, $violations);
            $this->logger->warning('Entry skipped', [
                'package' => $instance->package,
                'instance' => $instance->getKey(),
                'external_id' => $entry->externalId,
                'violations' => $violations,
            ]);

            return;
        }

        [$id, $outcome] = $this->writer->save(
            $instance->id,
            $entry->externalId,
            $entry->fingerprint,
            Slug::from($entry->slug ?? $entry->title),
            fn (): array => $this->values($entry),
        );
        $report->wrote($outcome);

        if ($outcome === WriteOutcome::Unchanged) {
            return;
        }

        $this->references->replace($id, $entry->references, $this->referenceFieldsOwnedBySource($entry->type));
        if ($outcome === WriteOutcome::Created) {
            $this->curation->inherit(Entry::findOrFail($id), $this->registry->contentType($entry->type));
        } else {
            $this->pruner->keepOnly($id, $entry->media);
        }
        foreach ($entry->media as $media) {
            ImportRemoteMedia::dispatch($id, $media);
        }
    }

    /**
     * A person may curate a Reference field too; a sync that replaced every reference of the entry would erase it.
     *
     * @return list<string>
     */
    private function referenceFieldsOwnedBySource(string $type): array
    {
        $owned = [];
        foreach ($this->registry->contentType($type)?->fields ?? [] as $field) {
            if ($field->type === FieldType::Reference && ! $field->curated) {
                $owned[] = $field->key;
            }
        }

        return $owned;
    }

    /** @return array<string, mixed> only what the source owns: never `curated`, never the slug */
    private function values(ExternalEntry $entry): array
    {
        return [
            'type' => $entry->type,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'body' => $entry->body !== null && $entry->bodyFormat === BodyFormat::Html
                ? $this->sanitizer->html($entry->body)
                : $entry->body,
            'body_format' => $entry->bodyFormat,
            'language' => $entry->language,
            'translation_key' => $entry->translationKey,
            'fields' => $entry->fields,
            'canonical_url' => $entry->canonicalUrl,
            'fingerprint' => $entry->fingerprint,
            'published_at' => $entry->publishedAt === null ? null : Carbon::instance($entry->publishedAt)->utc(),
            'source_updated_at' => $entry->updatedAt === null ? null : Carbon::instance($entry->updatedAt)->utc(),
            'synced_at' => Carbon::now('UTC'),
        ];
    }
}
