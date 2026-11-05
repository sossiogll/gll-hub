<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Entry;
use App\Models\EntryReference;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A curated value belongs to the content, not to one language of it, unless its field is translatable. Each
 * translation keeps a copy, so an entry reads alone; this class is what keeps those copies equal.
 *
 * Images are left out: a Media field has no value to copy, its files live in each entry's media library.
 */
final class SharedCuration
{
    public function __construct(private readonly ReferenceStore $references)
    {
    }

    public function spreadFrom(Entry $entry, ContentTypeDefinition $type): void
    {
        DB::transaction(function () use ($entry, $type): void {
            foreach ($this->translationsOf($entry) as $translation) {
                $this->copy($entry, $translation, $type);
            }
        });
    }

    /** A translation that arrives after the others starts from what the person already chose for them. */
    public function inherit(Entry $entry, ContentTypeDefinition $type): void
    {
        DB::transaction(function () use ($entry, $type): void {
            $from = $this->translationsOf($entry)->first();
            if ($from !== null) {
                $this->copy($from, $entry, $type);
            }
        });
    }

    /** @return Collection<int, Entry> the other translations, those in the bin too: a restored one must not be stale */
    private function translationsOf(Entry $entry): Collection
    {
        if ($entry->translation_key === null) {
            return new Collection();
        }

        return Entry::withTrashed()
            ->where('type', $entry->type)
            ->where('translation_key', $entry->translation_key)
            ->whereKeyNot($entry->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function copy(Entry $from, Entry $to, ContentTypeDefinition $type): void
    {
        $shared = array_filter($type->fields, static fn (Field $f): bool => $f->curated && ! $f->translatable && $f->type !== FieldType::Media);
        $keys = array_flip(array_map(static fn (Field $f): string => $f->key, $shared));

        $to->forceFill(['curated' => array_diff_key($to->curated, $keys) + array_intersect_key($from->curated, $keys)])->save();

        foreach ($shared as $field) {
            if ($field->type === FieldType::Reference) {
                $this->references->link($to->id, $field->key, $this->targets($from, $field->key));
            }
        }
    }

    /** @return list<int> */
    private function targets(Entry $entry, string $field): array
    {
        return EntryReference::where('entry_id', $entry->id)
            ->where('field', $field)
            ->whereNotNull('target_entry_id')
            ->orderBy('position')
            ->pluck('target_entry_id')
            ->all();
    }
}
