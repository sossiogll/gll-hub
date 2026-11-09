<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Entries\Pages\CreateEntry;
use App\Filament\Resources\Entries\Pages\EditEntry;
use App\Filament\Resources\Entries\Pages\ListEntries;
use App\Models\Entry;
use Livewire\Livewire;
use Tests\Fixtures\Entries;

final class EntryTest extends AdminTestCase
{
    public function test_a_hand_written_entry_is_created_with_the_fields_of_its_type(): void
    {
        Livewire::withQueryParams(['type' => 'book'])->test(CreateEntry::class)
            ->fillForm([
                'title' => 'Dune',
                'language' => 'en',
                'summary' => 'Sand.',
                'fields' => ['isbn' => '978', 'pages' => '412', 'tags' => ['sf'], 'stage' => 'draft', 'chapters' => [['title' => 'One']]],
                'curated' => ['featured' => true],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = Entry::sole();
        $this->assertSame('dune', $entry->slug);
        $this->assertTrue($entry->isHandWritten());
        $this->assertSame(['isbn' => '978', 'pages' => 412, 'tags' => ['sf'], 'stage' => 'draft', 'chapters' => [['title' => 'One']]], $entry->fields);
        $this->assertSame(['featured' => true], $entry->curated);
    }

    public function test_a_group_is_filled_inside_its_fieldset_and_stored_as_one_object(): void
    {
        Livewire::withQueryParams(['type' => 'book'])->test(CreateEntry::class)
            ->fillForm([
                'title' => 'Dune',
                'language' => 'en',
                'fields' => ['isbn' => '978', 'timeline' => ['place' => 'Arrakis', 'since' => '', 'rows' => [['message' => 'One']]]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['rows' => [['message' => 'One']], 'place' => 'Arrakis'], Entry::sole()->fields['timeline']);
    }

    public function test_a_group_that_breaks_the_rules_is_refused_where_it_is(): void
    {
        Livewire::withQueryParams(['type' => 'book'])->test(CreateEntry::class)
            ->fillForm(['title' => 'Dune', 'language' => 'en', 'fields' => ['isbn' => '978', 'timeline' => ['rows' => [['message' => 'One']]]]])
            ->call('create')
            ->assertHasErrors(['data.fields.timeline']);

        $this->assertSame(0, Entry::count());
    }

    public function test_the_cores_rules_refuse_what_does_not_fit_and_say_where(): void
    {
        Livewire::withQueryParams(['type' => 'book'])->test(CreateEntry::class)
            ->fillForm(['title' => 'Dune', 'language' => 'English', 'fields' => ['pages' => 'many'], 'curated' => ['blurb' => str_repeat('x', 21)]])
            ->call('create')
            ->assertHasErrors(['data.fields.isbn', 'data.fields.pages', 'data.curated.blurb', 'data.language']);

        $this->assertSame(0, Entry::count());
    }

    public function test_an_unknown_type_is_not_found(): void
    {
        $this->get('/admin/entries/create?type=nope')->assertNotFound();
    }

    public function test_a_hand_written_entry_can_be_edited_entirely(): void
    {
        $entry = $this->handWritten('Dune');

        Livewire::test(EditEntry::class, ['record' => $entry->getKey()])
            ->fillForm(['title' => 'Dune Messiah', 'fields' => ['isbn' => '2'], 'curated' => ['position' => '3']])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $entry->fresh();
        $this->assertSame('Dune Messiah', $fresh->title);
        $this->assertSame('dune', $fresh->slug);
        $this->assertSame(['isbn' => '2'], $fresh->fields);
        $this->assertSame(['featured' => false, 'position' => 3], $fresh->curated);
    }

    public function test_a_synced_entry_shows_what_the_source_owns_but_only_takes_curated_values(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Synced', ['summary' => 'From the source'])];
        $this->sync($this->makeInstance());
        $entry = Entry::sole();

        Livewire::test(EditEntry::class, ['record' => $entry->getKey()])
            ->assertSee('Synced')
            ->assertFormFieldIsDisabled('title')
            ->assertFormFieldIsDisabled('fields.isbn')
            ->assertFormFieldIsDisabled('fields.timeline.place')
            ->assertFormFieldIsEnabled('curated.featured')
            ->fillForm(['title' => 'Hijacked', 'summary' => 'Hijacked', 'fields' => ['isbn' => 'hijacked'], 'curated' => ['featured' => true, 'blurb' => 'Read it']])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $entry->fresh();
        $this->assertSame('Synced', $fresh->title);
        $this->assertSame('From the source', $fresh->summary);
        $this->assertSame(['isbn' => 'isbn-1'], $fresh->fields);
        $this->assertSame(['featured' => true, 'blurb' => 'Read it'], $fresh->curated);
    }

    public function test_curated_values_of_a_synced_entry_are_validated_too(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1')];
        $this->sync($this->makeInstance());

        Livewire::test(EditEntry::class, ['record' => Entry::sole()->getKey()])
            ->fillForm(['curated' => ['blurb' => str_repeat('x', 21)]])
            ->call('save')
            ->assertHasErrors(['data.curated.blurb']);
    }

    public function test_the_list_shows_origin_and_dates_and_can_show_trashed_entries(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Synced')];
        $instance = $this->makeInstance(attributes: ['label' => 'Main shelf']);
        $this->sync($instance);
        $synced = Entry::sole();
        $mine = $this->handWritten('Mine');
        $gone = $this->handWritten('Gone');
        $gone->delete();

        Livewire::test(ListEntries::class)
            ->set('activeTab', 'book')
            ->assertCanSeeTableRecords([$synced, $mine])
            ->assertCanNotSeeTableRecords([$gone])
            ->assertSee('Main shelf')
            ->assertSee('Written by hand')
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$gone])
            ->assertCanNotSeeTableRecords([$mine]);
    }

    public function test_the_entries_of_one_instance_can_be_listed(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Synced')];
        $instance = $this->makeInstance();
        $this->sync($instance);
        $mine = $this->handWritten('Mine');

        Livewire::test(ListEntries::class)
            ->filterTable('origin', $instance->id)
            ->assertCanSeeTableRecords([Entry::where('title', 'Synced')->sole()])
            ->assertCanNotSeeTableRecords([$mine]);
    }
}
