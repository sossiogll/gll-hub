<?php

declare(strict_types=1);

namespace App\Content\Reading;

use App\Models\Entry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which row stands for a piece of content in a language: the one asked for, then the main one, then a fixed order.
 * Rows without a translation key each stand for themselves.
 */
final class TranslationPreference
{
    public function __construct(private readonly Languages $languages)
    {
    }

    /**
     * @param Builder<Entry> $candidates
     * @return Builder<Entry> one entry per piece of content
     */
    public function best(Builder $candidates, string $requested): Builder
    {
        $ranked = (clone $candidates)->toBase()
            ->select('id')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY translation_key, CASE WHEN translation_key IS NULL THEN id END'
                . ' ORDER BY CASE WHEN language = ? THEN 0 WHEN language = ? THEN 1 ELSE 2 END, id) AS preference',
                [$requested, $this->languages->default()],
            );

        return Entry::query()->whereIn('id', DB::query()->fromSub($ranked, 'ranked')->where('preference', 1)->select('id'));
    }
}
