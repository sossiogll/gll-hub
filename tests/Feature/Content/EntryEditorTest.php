<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Content\EntryEditor;
use App\Content\EntryInput;
use App\Content\InvalidEntry;
use App\Content\ReferenceStore;
use App\Models\Entry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Tests\Fixtures\Entries;
use Tests\HubTestCase;

final class EntryEditorTest extends HubTestCase
{
    private EntryEditor $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->app->make(EntryEditor::class);
    }

    /** @param array<string, mixed> $overrides */
    private function input(array $overrides = []): EntryInput
    {
        return new EntryInput(...($overrides + [
            'title' => 'Dune',
            'slug' => null,
            'summary' => null,
            'body' => null,
            'language' => 'en',
            'publishedAt' => null,
            'fields' => ['isbn' => '978'],
            'curated' => [],
        ]));
    }

    public function test_a_markdown_body_written_by_hand_keeps_its_comments(): void
    {
        $body = "Text.\n\n<!-- gll:demo -->\n";

        $entry = $this->editor->create('book', $this->input(['body' => $body]), null);

        $this->assertSame($body, $entry->fresh()->body);
    }

    /** @return list<string> */
    private function violationsOf(callable $write): array
    {
        try {
            $write();
        } catch (InvalidEntry $e) {
            return $e->violations;
        }

        $this->fail('The entry was accepted');
    }

    public function test_a_new_entry_gets_a_slug_from_its_title_and_every_value_in_its_place(): void
    {
        $other = $this->editor->create('book', $this->input(['title' => 'Other']), null);

        $entry = $this->editor->create('book', $this->input([
            'summary' => 'Sand.',
            'body' => '# Spice',
            'publishedAt' => Carbon::parse('2026-03-01 12:00:00+02:00'),
            'fields' => ['isbn' => '978', 'pages' => 412, 'tags' => ['sf'], 'related' => [$other->id]],
            'curated' => ['featured' => true, 'sequel' => $other->id],
        ]), null)->fresh();

        $this->assertSame('dune', $entry->slug);
        $this->assertSame('book', $entry->type);
        $this->assertNull($entry->source_instance_id);
        $this->assertSame('Sand.', $entry->summary);
        $this->assertSame('markdown', $entry->body_format->value);
        $this->assertSame('2026-03-01 10:00:00', $entry->published_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(['isbn' => '978', 'pages' => 412, 'tags' => ['sf']], $entry->fields);
        $this->assertSame(['featured' => true], $entry->curated);
        $this->assertSame(['related' => [$other->id], 'sequel' => [$other->id]], $this->app->make(ReferenceStore::class)->targets($entry->id));
    }

    public function test_two_entries_with_the_same_title_get_different_slugs(): void
    {
        $this->assertSame('dune', $this->editor->create('book', $this->input(), null)->slug);
        $this->assertSame('dune-2', $this->editor->create('book', $this->input(), null)->slug);
        $this->assertSame('a-chosen', $this->editor->create('book', $this->input(['slug' => 'A Chosen!']), null)->slug);
    }

    public function test_the_rules_sources_are_held_to_apply_to_what_is_written_by_hand(): void
    {
        $violations = $this->violationsOf(fn () => $this->editor->create('book', $this->input([
            'title' => ' ',
            'language' => 'English',
            'fields' => ['pages' => 'many'],
            'curated' => ['blurb' => str_repeat('x', 21)],
        ]), null));

        foreach ([
            'the title is empty',
            'language "English" is not a language tag',
            'field "pages" must be an integer',
            'field "blurb" is longer than 20 characters',
            'required field "isbn" is missing',
        ] as $expected) {
            $this->assertStringContainsString($expected, implode("\n", $violations));
        }
        $this->assertSame(0, Entry::count());
    }

    public function test_a_reference_must_name_an_entry_of_an_allowed_type(): void
    {
        $other = $this->editor->create('book', $this->input(['title' => 'Other']), null);
        $foreign = (new Entry())->forceFill(['type' => 'film', 'slug' => 'f', 'title' => 'F']);
        $foreign->save();

        $violations = implode("\n", $this->violationsOf(fn () => $this->editor->create('book', $this->input([
            'fields' => ['isbn' => '1', 'related' => [$other->id, 999, $foreign->id]],
        ]), null)));

        $this->assertStringContainsString('points at entry 999, which does not exist', $violations);
        $this->assertStringContainsString("points at entry {$foreign->id}, a film, but only book can be linked", $violations);
        $this->assertStringNotContainsString("entry {$other->id}", $violations);
    }

    public function test_a_hand_written_entry_keeps_its_url_when_the_title_changes_and_moves_it_on_request(): void
    {
        $entry = $this->editor->create('book', $this->input(), null);
        $this->editor->create('book', $this->input(['title' => 'Taken']), null);

        $this->editor->update($entry, $this->input(['title' => 'Dune Messiah']));
        $this->assertSame('dune', $entry->fresh()->slug);
        $this->assertSame('Dune Messiah', $entry->fresh()->title);

        $this->editor->update($entry, $this->input(['title' => 'Dune Messiah', 'slug' => 'Taken']));
        $this->assertSame('taken-2', $entry->fresh()->slug);

        $this->editor->update($entry, $this->input(['title' => 'Dune Messiah', 'language' => 'it']));
        $this->assertSame('taken-2', $entry->fresh()->slug);
        $this->assertSame('it', $entry->fresh()->language);
    }

    public function test_a_synced_entry_only_takes_its_curated_values(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Synced', ['fields' => ['isbn' => 'source'], 'summary' => 'From the source'])];
        $this->sync($instance);
        $entry = Entry::sole();
        $entry->update(['curated' => ['position' => 2, 'retired' => 'stale']]);
        $other = $this->editor->create('book', $this->input(['title' => 'Other']), null);

        $this->editor->update($entry, $this->input([
            'title' => 'Hijacked',
            'slug' => 'hijacked',
            'summary' => 'Hijacked',
            'language' => 'it',
            'fields' => ['isbn' => 'hijacked'],
            'curated' => ['featured' => true, 'sequel' => $other->id],
        ]));

        $entry = $entry->fresh();
        $this->assertSame('Synced', $entry->title);
        $this->assertSame('synced', $entry->slug);
        $this->assertSame('From the source', $entry->summary);
        $this->assertSame('en', $entry->language);
        $this->assertSame(['isbn' => 'source'], $entry->fields);
        // The values of declared curated fields are replaced, an undeclared leftover is not thrown away.
        $this->assertSame(['retired' => 'stale', 'featured' => true], $entry->curated);
        $this->assertSame(['sequel' => [$other->id]], $this->app->make(ReferenceStore::class)->targets($entry->id));
    }

    public function test_a_synced_entry_refuses_invalid_curated_values_and_those_that_are_not_curated(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1')];
        $this->sync($instance);

        $violations = implode("\n", $this->violationsOf(fn () => $this->editor->update(Entry::sole(), $this->input([
            'curated' => ['blurb' => str_repeat('x', 21), 'isbn' => 'x'],
        ]))));

        $this->assertStringContainsString('field "blurb" is longer than 20 characters', $violations);
        $this->assertStringContainsString('field "isbn" belongs to the source, it cannot be curated', $violations);
        $this->assertSame([], Entry::sole()->curated);
    }

    public function test_an_entry_of_a_type_that_is_no_longer_installed_cannot_be_written(): void
    {
        $this->assertSame(['content type "film" is not installed'], $this->violationsOf(fn () => $this->editor->create('film', $this->input(), null)));
    }

    public function test_the_published_date_is_kept_in_utc(): void
    {
        $at = Carbon::parse('2026-07-01 09:30:00', 'Europe/Rome');
        $this->assertInstanceOf(CarbonInterface::class, $at);

        $entry = $this->editor->create('book', $this->input(['publishedAt' => $at]), null)->fresh();

        $this->assertSame('2026-07-01 07:30:00', $entry->published_at->utc()->format('Y-m-d H:i:s'));
    }
}
