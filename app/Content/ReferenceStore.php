<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Rules\Slug;
use App\Models\EntryReference;
use Gll\Hub\Contracts\Source\ExternalRef;
use Illuminate\Support\Facades\DB;

/**
 * Keeps what an entry refers to. A source says what it refers to (ReferenceResolver turns that into links
 * between entries); a person picks the entries themselves. Either way a field is replaced whole, and a writer
 * never touches the fields of the other: the source's, and the ones a person curates.
 */
final class ReferenceStore
{
    /**
     * @param list<ExternalRef> $references the complete set for the fields the source owns; what is not in it is dropped
     * @param list<string> $ownedFields the Reference fields the source owns; references to any other are ignored
     */
    public function replace(int $entryId, array $references, array $ownedFields): void
    {
        $position = [];
        $rows = [];
        foreach ($references as $ref) {
            if (! in_array($ref->field, $ownedFields, true)) {
                continue;
            }
            $rows[] = [
                'entry_id' => $entryId,
                'field' => $ref->field,
                'position' => $position[$ref->field] = ($position[$ref->field] ?? -1) + 1,
                'external_id' => $ref->externalId,
                'type' => $ref->type,
                // A source may name its target as people write it; entries are stored under this form.
                'slug' => $ref->slug === null ? null : Slug::from($ref->slug),
            ];
        }

        DB::transaction(function () use ($entryId, $ownedFields, $rows): void {
            EntryReference::where('entry_id', $entryId)->whereIn('field', $ownedFields)->delete();
            if ($rows !== []) {
                EntryReference::insert($rows);
            }
        });
    }

    /**
     * Points one field of an entry at entries chosen by hand.
     *
     * @param list<int> $targetIds
     */
    public function link(int $entryId, string $field, array $targetIds): void
    {
        $rows = [];
        foreach (array_values(array_unique($targetIds)) as $position => $targetId) {
            $rows[] = [
                'entry_id' => $entryId,
                'field' => $field,
                'position' => $position,
                'target_entry_id' => $targetId,
                'external_id' => null,
                'type' => null,
                'slug' => null,
            ];
        }

        DB::transaction(function () use ($entryId, $field, $rows): void {
            EntryReference::where('entry_id', $entryId)->where('field', $field)->delete();
            if ($rows !== []) {
                EntryReference::insert($rows);
            }
        });
    }

    /**
     * The entries an entry points at, by field. A reference that no entry answers yet is not listed.
     *
     * @return array<string, list<int>>
     */
    public function targets(int $entryId): array
    {
        $found = [];
        foreach (EntryReference::where('entry_id', $entryId)->whereNotNull('target_entry_id')->orderBy('field')->orderBy('position')->get(['field', 'target_entry_id']) as $row) {
            $found[$row->field][] = $row->target_entry_id;
        }

        return $found;
    }
}
