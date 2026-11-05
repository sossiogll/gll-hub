<?php

declare(strict_types=1);

namespace App\Content\Reading;

use App\Models\Entry;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;
use Gll\Hub\Contracts\Source\BodyFormat;
use Illuminate\Database\Eloquent\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Entries as the API serves them. What several entries need is read once for the whole page. */
final class EntryPresenter
{
    public function __construct(private readonly ReferenceReader $references)
    {
    }

    /**
     * @param Collection<int, Entry> $entries
     * @param list<string>|null $only the fields to serve; null = all of them
     * @return list<array<string, mixed>>
     */
    public function present(Collection $entries, ContentTypeDefinition $type, string $language, bool $full, ?array $only): array
    {
        $entries->load('media');
        $translations = $this->translations($entries, $type->key);
        $references = $this->references->of($entries->modelKeys(), $language);

        return $entries->map(fn (Entry $entry): array => [
            'type' => $entry->type,
            'slug' => $entry->slug,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'language' => $entry->language,
            'requested_language' => $language,
            'translation_key' => $entry->translation_key,
            'translations' => $translations[$entry->translation_key] ?? [['language' => $entry->language, 'slug' => $entry->slug]],
            'canonical_url' => $entry->canonical_url,
            'published_at' => FieldValue::time($entry->published_at),
            'updated_at' => FieldValue::time($entry->source_updated_at ?? $entry->updated_at),
            'fields' => (object) $this->fields($entry, $type, $references[$entry->id] ?? [], $full, $only),
            ...($full ? $this->body($entry) : []),
        ])->values()->all();
    }

    /**
     * @param Collection<int, Entry> $entries
     * @return array<string, list<array{language: string, slug: string}>> by translation key
     */
    private function translations(Collection $entries, string $type): array
    {
        $keys = $entries->pluck('translation_key')->filter()->unique()->all();
        if ($keys === []) {
            return [];
        }

        $translations = [];
        foreach (Entry::published()->where('type', $type)->whereIn('translation_key', $keys)->orderBy('language')->get(['translation_key', 'language', 'slug']) as $row) {
            $translations[$row->translation_key][] = ['language' => $row->language, 'slug' => $row->slug];
        }

        return $translations;
    }

    /**
     * @param array<string, list<array<string, string>>> $references
     * @param list<string>|null $only
     * @return array<string, mixed>
     */
    private function fields(Entry $entry, ContentTypeDefinition $type, array $references, bool $full, ?array $only): array
    {
        $copies = $this->copies($entry);
        $values = [];

        foreach ($type->fields as $field) {
            if ($only !== null && ! in_array($field->key, $only, true)) {
                continue;
            }

            $value = match ($field->type) {
                FieldType::Media => $this->media($entry, $field),
                FieldType::Reference => $field->multiple ? ($references[$field->key] ?? []) : ($references[$field->key][0] ?? null),
                default => FieldValue::shape($field, ($field->curated ? $entry->curated : $entry->fields)[$field->key] ?? null, $full, $copies),
            };

            $hasEmptyForm = $field->type === FieldType::Media || $field->type === FieldType::Reference;
            if ($value !== null || $hasEmptyForm) {
                $values[$field->key] = $value;
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function body(Entry $entry): array
    {
        return [
            'body' => $entry->body === null ? null : strtr($entry->body, $this->copies($entry)),
            'body_format' => ($entry->body_format ?? BodyFormat::Markdown)->value,
        ];
    }

    /** @return array<string, mixed>|list<array<string, mixed>>|null one file or none, or a list when the field holds several */
    private function media(Entry $entry, Field $field): ?array
    {
        $files = $entry->media
            ->where('collection_name', $field->key)
            ->sortBy('order_column')
            ->map(self::file(...))
            ->values()
            ->all();

        return $field->multiple ? $files : ($files[0] ?? null);
    }

    /** @return array<string, mixed> */
    private static function file(Media $file): array
    {
        return [
            'url' => $file->getFullUrl(),
            'alt' => $file->getCustomProperty('alt') ?: null,
            'width' => $file->getCustomProperty('width'),
            'height' => $file->getCustomProperty('height'),
            'mime' => $file->mime_type ?: 'application/octet-stream',
        ];
    }

    /**
     * Text points at the source's URL until its copy is imported, then at the copy.
     *
     * @return array<string, string>
     */
    private function copies(Entry $entry): array
    {
        $copies = [];
        foreach ($entry->media as $file) {
            $source = $file->getCustomProperty('source_url');
            if (is_string($source) && $source !== '') {
                $copies[$source] = $file->getFullUrl();
            }
        }

        return $copies;
    }
}
