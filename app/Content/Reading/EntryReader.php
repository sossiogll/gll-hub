<?php

declare(strict_types=1);

namespace App\Content\Reading;

use App\Models\Entry;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Illuminate\Database\Eloquent\Collection;

/** Finds what the API serves: only what is published, one row per piece of content. */
final class EntryReader
{
    public function __construct(private readonly TranslationPreference $preference)
    {
    }

    /**
     * @param list<SortKey> $sort
     * @return array{0: Collection<int, Entry>, 1: int} the page, and the total across pages
     */
    public function page(string $type, string $language, array $sort, ContentTypeDefinition $definition, int $page, int $perPage): array
    {
        $best = $this->preference->best(Entry::published()->where('type', $type), $language);

        return [
            EntrySort::apply((clone $best), $definition, $sort)->forPage($page, $perPage)->get(),
            (clone $best)->count(),
        ];
    }

    /** A slug may belong to any translation. The language asked for wins when several pieces of content share it. */
    public function find(string $type, string $slug, string $language): ?Entry
    {
        $found = Entry::published()
            ->where('type', $type)
            ->where('slug', $slug)
            ->orderByRaw('CASE WHEN language = ? THEN 0 ELSE 1 END', [$language])
            ->orderBy('id')
            ->first(['id', 'type', 'translation_key']);

        if ($found === null || $found->translation_key === null) {
            return $found === null ? null : Entry::find($found->id);
        }

        return $this->preference
            ->best(Entry::published()->where('type', $type)->where('translation_key', $found->translation_key), $language)
            ->first();
    }
}
