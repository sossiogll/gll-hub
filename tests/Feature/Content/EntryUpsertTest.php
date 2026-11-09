<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Sync\SyncReport;
use App\Content\SlugAllocator;
use App\Content\ReferenceStore;
use App\Models\Entry;
use App\Models\EntryReference;
use App\Models\SyncStatus;
use App\Models\User;
use DateTimeImmutable;
use Gll\Hub\Contracts\Source\BodyFormat;
use Gll\Hub\Contracts\Source\ExternalRef;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Entries;
use Tests\HubTestCase;

final class EntryUpsertTest extends HubTestCase
{
    public function test_a_new_entry_is_created_from_what_the_source_returned(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', [
            'summary' => 'Sand.',
            'body' => '# Spice',
            'language' => 'it',
            'translationKey' => 'dune',
            'fields' => ['isbn' => '978', 'pages' => 412, 'tags' => ['sf', 'classic'], 'stage' => 'published'],
            'publishedAt' => new DateTimeImmutable('2026-03-01T12:00:00+02:00'),
            'updatedAt' => new DateTimeImmutable('2026-03-02T00:00:00Z'),
            'canonicalUrl' => 'https://books.test/dune',
        ])];

        $report = $this->sync($instance);

        $this->assertSame(1, $report->created);
        $entry = Entry::sole();
        $this->assertSame('book', $entry->type);
        $this->assertSame('dune', $entry->slug);
        $this->assertSame('Dune', $entry->title);
        $this->assertSame('Sand.', $entry->summary);
        $this->assertSame('# Spice', $entry->body);
        $this->assertSame(BodyFormat::Markdown, $entry->body_format);
        $this->assertSame('it', $entry->language);
        $this->assertSame('dune', $entry->translation_key);
        $this->assertSame(['isbn' => '978', 'pages' => 412, 'tags' => ['sf', 'classic'], 'stage' => 'published'], $entry->fields);
        $this->assertSame([], $entry->curated);
        $this->assertSame($instance->id, $entry->source_instance_id);
        $this->assertSame('1', $entry->external_id);
        $this->assertSame('https://books.test/dune', $entry->canonical_url);
        $this->assertNotNull($entry->fingerprint);
        $this->assertNotNull($entry->synced_at);
        $this->assertNull($entry->author_id);
    }

    public function test_dates_are_stored_in_utc(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', [
            'publishedAt' => new DateTimeImmutable('2026-03-01T12:00:00+02:00'),
        ])];

        $this->sync($this->makeInstance());

        $this->assertSame('2026-03-01 10:00:00', DB::table('entries')->value('published_at'));
        $this->assertSame('UTC', Entry::sole()->published_at->getTimezone()->getName());
    }

    public function test_a_changed_entry_is_updated_and_keeps_its_slug_and_id(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];
        $this->sync($instance);
        $id = Entry::sole()->id;

        $this->script->entries['catalog'] = [Entries::book('1', 'Dune Messiah', ['fields' => ['isbn' => '2', 'pages' => 300]])];
        $report = $this->sync($instance);

        $this->assertSame(1, $report->updated);
        $entry = Entry::sole();
        $this->assertSame($id, $entry->id);
        $this->assertSame('Dune Messiah', $entry->title);
        $this->assertSame('dune', $entry->slug, 'a URL does not move when a title changes');
        $this->assertSame(['isbn' => '2', 'pages' => 300], $entry->fields);
    }

    public function test_an_entry_with_the_same_fingerprint_is_not_written(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];
        $this->sync($instance);
        $before = Entry::sole();

        $this->travel(5)->minutes();
        $report = $this->sync($instance);

        $this->assertSame(1, $report->unchanged);
        $this->assertSame(0, $report->updated);
        $this->assertTrue($before->synced_at->equalTo(Entry::sole()->synced_at));
    }

    public function test_an_entry_without_a_fingerprint_is_always_written(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', ['fingerprint' => null])];
        $this->sync($instance);
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', ['fingerprint' => null])];

        $this->assertNull($this->script->entries['catalog'][0]->fingerprint);
        $this->assertSame(1, $this->sync($instance)->updated);
    }

    public function test_curated_values_survive_every_sync(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];
        $this->sync($instance);
        Entry::sole()->update(['curated' => ['featured' => true, 'position' => 3, 'blurb' => 'Read it']]);

        $this->script->entries['catalog'] = [Entries::book('1', 'Dune, revised', ['fields' => ['isbn' => '9']])];
        $this->sync($instance);

        $entry = Entry::sole();
        $this->assertSame('Dune, revised', $entry->title);
        $this->assertSame(['featured' => true, 'position' => 3, 'blurb' => 'Read it'], $entry->curated);
        $this->assertSame(['isbn' => '9'], $entry->fields);
    }

    public function test_the_author_of_an_entry_is_not_touched_by_a_sync(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];
        $this->sync($instance);
        $author = User::factory()->create();
        Entry::sole()->update(['author_id' => $author->id]);

        $this->script->entries['catalog'] = [Entries::book('1', 'Dune 2')];
        $this->sync($instance);

        $this->assertSame($author->id, Entry::sole()->author_id);
    }

    public function test_slugs_are_unique_per_type_and_language(): void
    {
        $this->script->entries['catalog'] = [
            Entries::book('1', 'Same title'),
            Entries::book('2', 'Same title'),
            Entries::book('3', 'Other title', ['slug' => 'Same Title']),
            Entries::book('4', 'Same title', ['language' => 'it']),
        ];

        $this->sync($this->makeInstance());

        $this->assertSame(
            ['1' => 'same-title', '2' => 'same-title-2', '3' => 'same-title-3', '4' => 'same-title'],
            Entry::orderBy('id')->pluck('slug', 'external_id')->all(),
        );
    }

    public function test_the_same_external_id_in_two_instances_makes_two_entries(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];

        $this->sync($this->makeInstance());
        $this->sync($this->makeInstance('catalog', ['label' => 'Second']));

        $this->assertSame(['dune', 'dune-2'], Entry::orderBy('id')->pluck('slug')->all());
    }

    public function test_a_slug_claimed_after_it_was_chosen_is_chosen_again(): void
    {
        $instance = $this->makeInstance();
        // A writer that wins the slug between our choice and our insert.
        $this->app->bind(SlugAllocator::class, fn () => new class () extends SlugAllocator {
            private int $calls = 0;

            public function free(string $type, string $language, string $base): string
            {
                if ($this->calls++ === 0) {
                    Entry::create(['type' => $type, 'language' => $language, 'slug' => $base, 'title' => 'Rival']);

                    return $base;
                }

                return parent::free($type, $language, $base);
            }
        });
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];

        $this->sync($instance);

        $this->assertSame(['dune', 'dune-2'], Entry::orderBy('id')->pluck('slug')->all());
        $this->assertSame('1', Entry::where('slug', 'dune-2')->sole()->external_id);
    }

    public function test_losing_the_race_to_create_the_same_entry_updates_it_instead_of_failing(): void
    {
        $instance = $this->makeInstance();
        $this->app->bind(SlugAllocator::class, fn () => new class ($instance->id) extends SlugAllocator {
            private bool $raced = false;

            public function __construct(private readonly int $instanceId)
            {
            }

            public function free(string $type, string $language, string $base): string
            {
                if (! $this->raced) {
                    $this->raced = true;
                    Entry::create(['type' => $type, 'language' => $language, 'slug' => 'rival', 'title' => 'Written first'])
                        ->forceFill(['source_instance_id' => $this->instanceId, 'external_id' => '1', 'fingerprint' => 'other'])
                        ->save();
                }

                return $base;
            }
        });
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];

        $report = $this->sync($instance);

        $this->assertSame(1, $report->updated);
        $entry = Entry::sole();
        $this->assertSame('Dune', $entry->title);
        $this->assertSame('rival', $entry->slug);
    }

    public function test_html_bodies_are_sanitized_once_before_they_are_stored(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', [
            'bodyFormat' => BodyFormat::Html,
            'body' => '<p onclick="steal()">Hi <script>alert(1)</script><a href="javascript:alert(2)">bad</a> '
                . '<a href="https://ok.test/page">ok</a><img src="https://img.test/a.png" onerror="steal()"><iframe src="https://evil.test"></iframe></p>',
        ])];

        $this->sync($this->makeInstance());

        $entry = Entry::sole();
        $this->assertSame(BodyFormat::Html, $entry->body_format);
        foreach (['<script', 'onclick', 'onerror', 'javascript:', '<iframe'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $entry->body);
        }
        $this->assertStringContainsString('Hi', $entry->body);
        $this->assertStringContainsString('href="https://ok.test/page"', $entry->body);
        $this->assertStringContainsString('rel="noopener noreferrer"', $entry->body);
        $this->assertStringContainsString('src="https://img.test/a.png"', $entry->body);
    }

    public function test_markdown_is_stored_as_given(): void
    {
        $markdown = "# Title\n\nSome <b>inline</b> html and a [link](https://a.test).";
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', ['body' => $markdown])];

        $this->sync($this->makeInstance());

        $this->assertSame($markdown, Entry::sole()->body);
    }

    public function test_an_invalid_entry_is_skipped_and_reported_without_failing_the_run(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [
            Entries::book('bad', 'No isbn', ['fields' => []]),
            Entries::book('worse', 'Wrong type', ['type' => 'film']),
            Entries::book('good', 'Fine'),
        ];

        $report = $this->sync($instance);

        $this->assertSame(1, $report->created);
        $this->assertSame(['good'], Entry::pluck('external_id')->all());
        $this->assertSame(['bad', 'worse'], array_map(fn ($r) => $r->externalId, $report->refusals));
        $this->assertSame([SyncReport::REFUSED_BY_RULES, SyncReport::REFUSED_BY_RULES], array_map(fn ($r) => $r->code, $report->refusals));
        $this->assertStringContainsString('required field "isbn" is missing', $report->refusals[0]->reasons[0]);
        $this->assertStringContainsString('"film" is not in the source\'s produces', $report->refusals[1]->reasons[0]);
        $this->assertStringContainsString('2 refused', $report->summary());
    }

    public function test_an_entry_the_source_stopped_listing_comes_back_when_it_is_listed_again(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];
        $this->sync($instance);
        $this->script->entries['catalog'] = [];
        $this->sync($instance);
        $this->assertSame(0, Entry::count());

        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];
        $report = $this->sync($instance);

        $this->assertSame(1, $report->updated, 'same fingerprint, but it was deleted');
        $this->assertSame(1, Entry::count());
        $this->assertSame('dune', Entry::sole()->slug);
        $this->assertSame(1, Entry::withTrashed()->count());
    }

    public function test_references_are_stored_and_resolved_after_the_run(): void
    {
        $this->script->entries['catalog'] = [
            Entries::book('1', 'First', ['references' => [
                new ExternalRef('related', externalId: '2'),
                new ExternalRef('related', type: 'book', slug: 'not-yet'),
            ]]),
            Entries::book('2', 'Second'),
        ];

        $this->sync($this->makeInstance());

        $first = Entry::where('external_id', '1')->sole();
        $second = Entry::where('external_id', '2')->sole();
        $references = EntryReference::where('entry_id', $first->id)->orderBy('position')->get();
        $this->assertSame([0, 1], $references->pluck('position')->all());
        $this->assertSame($second->id, $references[0]->target_entry_id, 'an entry that comes later in the same run is found');
        $this->assertNull($references[1]->target_entry_id, 'an entry that does not exist yet stays unresolved');
        $this->assertSame(['book', 'not-yet'], [$references[1]->type, $references[1]->slug]);
    }

    public function test_a_markdown_body_keeps_its_comments(): void
    {
        $body = "Text.\n\n<!-- gll:demo -->\n\n<!-- gll:widget nome -->\n";
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', ['body' => $body])];

        $this->sync($this->makeInstance());

        $this->assertSame($body, Entry::sole()->body);
    }

    public function test_a_reference_by_a_name_as_people_write_it_finds_the_entry_with_that_title(): void
    {
        $this->script->entries['catalog'] = [
            Entries::book('1', 'First', ['references' => [new ExternalRef('related', type: 'book', slug: 'Later Book.org')]]),
            Entries::book('9', 'Later Book.org'),
        ];

        $this->sync($this->makeInstance());

        $this->assertSame(Entry::where('external_id', '9')->sole()->id, EntryReference::sole()->target_entry_id);
    }

    public function test_an_unresolved_reference_is_resolved_by_a_later_run_even_from_another_source(): void
    {
        $this->script->entries['catalog'] = [
            Entries::book('1', 'First', ['references' => [new ExternalRef('related', type: 'book', slug: 'later-book')]]),
        ];
        $this->sync($this->makeInstance());
        $this->assertNull(EntryReference::sole()->target_entry_id);

        $this->script->entries['latest'] = [Entries::book('9', 'Later book')];
        $this->sync($this->makeInstance('latest'));

        $this->assertSame(Entry::where('external_id', '9')->sole()->id, EntryReference::sole()->target_entry_id);
    }

    public function test_a_reference_by_slug_prefers_the_language_of_the_entry_that_refers(): void
    {
        $this->script->entries['catalog'] = [
            Entries::book('en', 'Target', ['slug' => 'target', 'language' => 'en']),
            Entries::book('it', 'Target', ['slug' => 'target', 'language' => 'it']),
            Entries::book('from-it', 'Source', ['language' => 'it', 'references' => [new ExternalRef('related', type: 'book', slug: 'target')]]),
        ];

        $this->sync($this->makeInstance());

        $this->assertSame(Entry::where('external_id', 'it')->sole()->id, EntryReference::sole()->target_entry_id);
    }

    public function test_references_are_replaced_when_the_entry_changes(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'First', ['references' => [
            new ExternalRef('related', type: 'book', slug: 'a'),
            new ExternalRef('related', type: 'book', slug: 'b'),
        ]])];
        $this->sync($instance);

        $this->script->entries['catalog'] = [Entries::book('1', 'First, again', ['references' => [new ExternalRef('related', type: 'book', slug: 'c')]])];
        $this->sync($instance);

        $this->assertSame(['c'], EntryReference::pluck('slug')->all());
    }

    public function test_a_reference_the_person_curates_survives_a_sync_that_replaces_the_sources(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'First'), Entries::book('2', 'Second')];
        $this->sync($instance);
        [$first, $second] = [Entry::where('external_id', '1')->sole(), Entry::where('external_id', '2')->sole()];
        $this->app->make(ReferenceStore::class)->link($first->id, 'sequel', [$second->id]);

        $this->script->entries['catalog'] = [
            Entries::book('1', 'First, again', ['references' => [
                new ExternalRef('related', type: 'book', slug: 'a'),
                new ExternalRef('sequel', type: 'book', slug: 'second'),
            ]]),
            Entries::book('2', 'Second'),
        ];
        $this->sync($instance);

        $this->assertSame(['related' => null], EntryReference::where('field', 'related')->pluck('target_entry_id', 'field')->all());
        $this->assertSame([$second->id], EntryReference::where('field', 'sequel')->pluck('target_entry_id')->all());
        $this->assertSame(['sequel' => [$second->id]], $this->app->make(ReferenceStore::class)->targets($first->id));
    }

    public function test_the_synchronizer_moves_data_and_leaves_the_status_to_the_job(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1')];

        $this->sync($instance);

        // Status belongs to the job (see SyncFailureTest); the synchronizer only moves data.
        $this->assertSame(SyncStatus::Pending, $instance->fresh()->status);
    }
}
