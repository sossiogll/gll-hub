<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Content\EntryEditor;
use App\Content\EntryInput;
use App\Content\ReferenceStore;
use App\Models\Entry;
use App\Models\EntryReference;
use Tests\Fixtures\Entries;
use Tests\HubTestCase;

final class SharedCurationTest extends HubTestCase
{
    /** @param array<string, mixed> $curated */
    private function curate(Entry $entry, array $curated): void
    {
        $this->app->make(EntryEditor::class)->update($entry, new EntryInput(
            title: $entry->title,
            slug: null,
            summary: null,
            body: null,
            language: $entry->language,
            publishedAt: null,
            fields: $entry->fields,
            curated: $curated,
        ));
    }

    /** @return array{0: Entry, 1: Entry} the two languages of one book */
    private function translations(): array
    {
        $this->script->entries['catalog'] = [
            Entries::book('1', 'Dune', ['translationKey' => 'k']),
            Entries::book('1:it', 'Dune', ['language' => 'it', 'translationKey' => 'k']),
        ];
        $this->sync($this->makeInstance());

        return [Entry::where('external_id', '1')->sole(), Entry::where('external_id', '1:it')->sole()];
    }

    public function test_what_is_not_translatable_is_the_same_in_every_translation(): void
    {
        [$english, $italian] = $this->translations();

        $this->curate($english, ['featured' => true, 'position' => 3]);

        $this->assertSame(['featured' => true, 'position' => 3], $italian->fresh()->curated);
    }

    public function test_a_translatable_value_stays_in_its_own_language(): void
    {
        [$english, $italian] = $this->translations();
        $this->curate($italian, ['blurb' => 'In italiano']);

        $this->curate($english, ['blurb' => 'In English', 'position' => 3]);

        $this->assertSame(['blurb' => 'In italiano', 'position' => 3], $italian->fresh()->curated);
        $this->assertSame('In English', $english->fresh()->curated['blurb']);
    }

    public function test_a_shared_value_taken_away_goes_from_every_translation(): void
    {
        [$english, $italian] = $this->translations();
        $this->curate($english, ['position' => 3]);

        $this->curate($italian, []);

        $this->assertSame([], $english->fresh()->curated);
    }

    public function test_a_translation_that_arrives_later_inherits_only_what_is_shared(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', ['translationKey' => 'k'])];
        $instance = $this->makeInstance();
        $this->sync($instance);
        $this->curate(Entry::sole(), ['position' => 3, 'blurb' => 'In English']);

        $this->script->entries['catalog'][] = Entries::book('1:it', 'Dune', ['language' => 'it', 'translationKey' => 'k']);
        $this->sync($instance);

        $this->assertSame(['position' => 3], Entry::where('external_id', '1:it')->sole()->curated);
    }

    public function test_a_curated_link_is_shared_too(): void
    {
        [$english, $italian] = $this->translations();
        $other = Entry::create(['type' => 'book', 'slug' => 'other', 'title' => 'Other']);

        $this->curate($english, ['sequel' => $other->id]);

        $this->assertSame($other->id, EntryReference::where('entry_id', $italian->id)->where('field', 'sequel')->sole()->target_entry_id);
    }

    public function test_a_later_translation_inherits_a_curated_link(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', ['translationKey' => 'k'])];
        $instance = $this->makeInstance();
        $this->sync($instance);
        $other = Entry::create(['type' => 'book', 'slug' => 'other', 'title' => 'Other']);
        $this->app->make(ReferenceStore::class)->link(Entry::where('external_id', '1')->sole()->id, 'sequel', [$other->id]);

        $this->script->entries['catalog'][] = Entries::book('1:it', 'Dune', ['language' => 'it', 'translationKey' => 'k']);
        $this->sync($instance);

        $italian = Entry::where('external_id', '1:it')->sole();
        $this->assertSame($other->id, EntryReference::where('entry_id', $italian->id)->where('field', 'sequel')->sole()->target_entry_id);
    }

    public function test_a_translation_in_the_bin_is_kept_equal_too(): void
    {
        [$english, $italian] = $this->translations();
        $italian->delete();

        $this->curate($english, ['position' => 3]);

        $this->assertSame(['position' => 3], Entry::withTrashed()->find($italian->id)->curated);
    }

    public function test_entries_without_a_translation_key_or_of_another_type_are_left_alone(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune'), Entries::book('2', 'Emma')];
        $this->sync($this->makeInstance());
        $stranger = Entry::create(['type' => 'other', 'slug' => 'x', 'title' => 'X', 'translation_key' => 'k']);

        $this->curate(Entry::where('external_id', '1')->sole(), ['position' => 3]);

        $this->assertSame([], Entry::where('external_id', '2')->sole()->curated);
        $this->assertSame([], $stranger->fresh()->curated);
    }
}
