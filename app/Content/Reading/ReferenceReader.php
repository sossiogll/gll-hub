<?php

declare(strict_types=1);

namespace App\Content\Reading;

use App\Models\Entry;
use App\Models\EntryReference;

/**
 * Resolved references as readers see them: each target in the language asked for when it has it, whichever translation
 * the link was first resolved to. What is unresolved or not published is not listed.
 */
final class ReferenceReader
{
    public function __construct(private readonly TranslationPreference $preference)
    {
    }

    /**
     * @param list<int> $entryIds
     * @return array<int, array<string, list<array{type: string, slug: string, title: string}>>> by entry id, by field
     */
    public function of(array $entryIds, string $language): array
    {
        $references = EntryReference::whereIn('entry_id', $entryIds)
            ->whereNotNull('target_entry_id')
            ->orderBy('position')
            ->get(['entry_id', 'field', 'target_entry_id']);

        $targets = $this->targets($references->pluck('target_entry_id')->unique()->all(), $language);

        $shaped = [];
        foreach ($references as $reference) {
            $target = $targets[$reference->target_entry_id] ?? null;
            if ($target !== null) {
                $shaped[$reference->entry_id][$reference->field][] = ['type' => $target->type, 'slug' => $target->slug, 'title' => $target->title];
            }
        }

        return $shaped;
    }

    /**
     * @param list<int> $ids
     * @return array<int, Entry> the row to show for each target id
     */
    private function targets(array $ids, string $language): array
    {
        if ($ids === []) {
            return [];
        }

        $columns = ['id', 'type', 'slug', 'title', 'translation_key'];
        $targets = Entry::published()->whereIn('id', $ids)->get($columns);
        $keys = $targets->pluck('translation_key')->filter()->unique()->all();

        $best = [];
        if ($keys !== []) {
            foreach ($this->preference->best(Entry::published()->whereIn('translation_key', $keys), $language)->get($columns) as $entry) {
                $best["{$entry->type} {$entry->translation_key}"] = $entry;
            }
        }

        $shown = [];
        foreach ($targets as $target) {
            $shown[$target->id] = $best["{$target->type} {$target->translation_key}"] ?? $target;
        }

        return $shown;
    }
}
