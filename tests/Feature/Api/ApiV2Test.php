<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Content\ImportRemoteMedia;
use App\Content\ReferenceResolver;
use App\Content\ReferenceStore;
use App\Models\Entry;
use App\Models\EntryReference;
use Gll\Hub\Contracts\Http\HttpClient;
use Gll\Hub\Contracts\Source\BodyFormat;
use Gll\Hub\Contracts\Source\ExternalRef;
use Gll\Hub\Contracts\Source\RemoteMedia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\Entries;
use Tests\HubTestCase;

final class ApiV2Test extends HubTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gllhub.languages' => ['default' => 'en', 'available' => ['en', 'it']]]);
    }

    /** @param array<string, mixed> $attributes */
    private function entry(string $slug, array $attributes = []): Entry
    {
        $entry = new Entry();
        $entry->forceFill($attributes + ['type' => 'book', 'slug' => $slug, 'title' => ucfirst($slug), 'published_at' => '2026-01-01 00:00:00'])->save();

        return $entry;
    }

    public function test_languages_are_those_declared(): void
    {
        $this->getJson('/api/v2/languages')->assertOk()->assertExactJson(['default' => 'en', 'available' => ['en', 'it']]);
    }

    public function test_languages_not_configured_is_unavailable_and_says_nothing_of_the_inside(): void
    {
        config(['gllhub.languages' => ['default' => null, 'available' => []]]);

        $response = $this->getJson('/api/v2/languages')->assertStatus(503)->assertJsonPath('code', 'unavailable');

        $this->assertStringNotContainsString('GLLHUB', (string) $response->getContent());
    }

    public function test_a_list_has_entries_without_their_text_and_the_meta(): void
    {
        $this->entry('dune', [
            'summary' => 'A summary', 'body' => 'The body', 'canonical_url' => 'https://example.test/dune',
            'fields' => ['isbn' => '1', 'notes' => 'Long notes', 'chapters' => [['title' => 'One', 'draft' => 'Long draft']]],
        ]);

        $response = $this->getJson('/api/v2/entries/book?language=en')->assertOk();

        $response->assertJsonPath('meta', ['page' => 1, 'per_page' => 20, 'total' => 1]);
        $entry = $response->json('data.0');
        $this->assertSame('book', $entry['type']);
        $this->assertSame('dune', $entry['slug']);
        $this->assertSame('A summary', $entry['summary']);
        $this->assertSame('en', $entry['language']);
        $this->assertSame('en', $entry['requested_language']);
        $this->assertSame([['language' => 'en', 'slug' => 'dune']], $entry['translations']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $entry['published_at']);
        $this->assertArrayNotHasKey('body', $entry);
        $this->assertArrayNotHasKey('body_format', $entry);
        $this->assertArrayNotHasKey('notes', $entry['fields']);
        $this->assertSame([['title' => 'One']], $entry['fields']['chapters'], 'Markdown goes at any depth');
    }

    public function test_a_detail_has_its_text(): void
    {
        $this->entry('dune', ['body' => 'The body', 'fields' => ['isbn' => '1', 'notes' => 'Long notes', 'chapters' => [['title' => 'One', 'draft' => 'Long draft']]]]);

        $entry = $this->getJson('/api/v2/entries/book/dune?language=en')->assertOk()->json();

        $this->assertSame('The body', $entry['body']);
        $this->assertSame('markdown', $entry['body_format']);
        $this->assertSame('Long notes', $entry['fields']['notes']);
        $this->assertSame('Long draft', $entry['fields']['chapters'][0]['draft']);
    }

    public function test_what_is_not_published_or_deleted_does_not_appear(): void
    {
        $this->entry('draft', ['published_at' => null]);
        Carbon::setTestNow('2026-06-01 00:00:00');
        $this->entry('later', ['published_at' => '2026-07-01 00:00:00']);
        $this->entry('gone')->delete();

        $this->getJson('/api/v2/entries/book?language=en')->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['draft', 'later', 'gone'] as $slug) {
            $this->getJson("/api/v2/entries/book/{$slug}?language=en")->assertNotFound()->assertJsonPath('code', 'not_found');
        }
    }

    public function test_a_list_has_one_entry_per_content_in_the_language_asked_for_or_the_main_one(): void
    {
        $this->entry('dune', ['translation_key' => 'a']);
        $this->entry('duna', ['translation_key' => 'a', 'language' => 'it']);
        $this->entry('emma', ['translation_key' => 'b']);
        $this->entry('solo', ['language' => 'it']);

        $italian = $this->getJson('/api/v2/entries/book?language=it&sort=slug')->assertOk();

        $this->assertSame(3, $italian->json('meta.total'));
        $this->assertSame(['duna', 'emma', 'solo'], array_column($italian->json('data'), 'slug'));
        $this->assertSame(['it', 'en', 'it'], array_column($italian->json('data'), 'language'));
        $this->assertSame(['it', 'it', 'it'], array_column($italian->json('data'), 'requested_language'));
        $this->assertSame(['dune', 'emma', 'solo'], array_column($this->getJson('/api/v2/entries/book?language=en&sort=slug')->json('data'), 'slug'));
        $this->assertSame(['dune', 'emma', 'solo'], array_column($this->getJson('/api/v2/entries/book?language=de&sort=slug')->json('data'), 'slug'), 'a language nobody has falls to the main one');
    }

    public function test_a_translation_that_is_not_published_does_not_stand_for_the_content(): void
    {
        $this->entry('dune', ['translation_key' => 'a']);
        $this->entry('duna', ['translation_key' => 'a', 'language' => 'it', 'published_at' => null]);

        $entry = $this->getJson('/api/v2/entries/book?language=it')->json('data.0');

        $this->assertSame('dune', $entry['slug']);
        $this->assertSame([['language' => 'en', 'slug' => 'dune']], $entry['translations']);
    }

    public function test_a_detail_finds_a_slug_of_any_language_and_answers_with_the_translation_asked_for(): void
    {
        $this->entry('dune', ['translation_key' => 'a']);
        $this->entry('duna', ['translation_key' => 'a', 'language' => 'it']);

        $entry = $this->getJson('/api/v2/entries/book/dune?language=it')->assertOk()->json();

        $this->assertSame('duna', $entry['slug']);
        $this->assertSame('it', $entry['language']);
        $this->assertEqualsCanonicalizing([['language' => 'en', 'slug' => 'dune'], ['language' => 'it', 'slug' => 'duna']], $entry['translations']);
    }

    public function test_a_detail_without_the_translation_answers_with_the_main_language(): void
    {
        $this->entry('dune', ['translation_key' => 'a']);

        $this->getJson('/api/v2/entries/book/dune?language=it')->assertOk()
            ->assertJsonPath('language', 'en')
            ->assertJsonPath('requested_language', 'it');
    }

    public function test_when_two_contents_share_a_slug_the_language_asked_for_wins(): void
    {
        $this->entry('same', ['translation_key' => 'a', 'title' => 'English one']);
        $this->entry('same', ['translation_key' => 'b', 'language' => 'it', 'title' => 'Quello italiano']);

        $this->assertSame('Quello italiano', $this->getJson('/api/v2/entries/book/same?language=it')->json('title'));
        $this->assertSame('English one', $this->getJson('/api/v2/entries/book/same?language=en')->json('title'));
    }

    public function test_fields_join_what_the_source_owns_and_what_is_curated_and_drop_what_is_undeclared(): void
    {
        $this->entry('dune', ['fields' => ['isbn' => '1', 'dropped_by_the_package' => 'x'], 'curated' => ['featured' => true, 'position' => 2]]);

        $fields = $this->getJson('/api/v2/entries/book/dune?language=en')->json('fields');

        $this->assertSame('1', $fields['isbn']);
        $this->assertTrue($fields['featured']);
        $this->assertSame(2, $fields['position']);
        $this->assertArrayNotHasKey('dropped_by_the_package', $fields);
    }

    public function test_the_fields_parameter_serves_only_those_and_the_rest_of_the_entry_stays(): void
    {
        $this->entry('dune', ['body' => 'Body', 'fields' => ['isbn' => '1', 'pages' => 2], 'curated' => ['position' => 3]]);

        $entry = $this->getJson('/api/v2/entries/book/dune?language=en&fields=isbn,position')->assertOk()->json();

        $this->assertSame(['isbn' => '1', 'position' => 3], $entry['fields']);
        $this->assertSame('Body', $entry['body']);
    }

    public function test_an_entry_with_no_fields_has_an_empty_object(): void
    {
        $this->entry('dune');

        $this->assertStringContainsString('"fields":{}', (string) $this->getJson('/api/v2/entries/book?language=en&fields=isbn')->getContent());
    }

    public function test_sort_uses_declared_fields_curated_too_with_numbers_as_numbers_and_empty_values_last(): void
    {
        $this->entry('a', ['curated' => ['position' => 10]]);
        $this->entry('b', ['curated' => ['position' => 9, 'featured' => true]]);
        $this->entry('c', ['published_at' => '2026-02-01 00:00:00']);
        $this->entry('d', ['curated' => ['featured' => false]]);

        $slugs = fn (string $sort): array => array_column($this->getJson("/api/v2/entries/book?language=en&sort={$sort}")->assertOk()->json('data'), 'slug');

        $this->assertSame(['b', 'a', 'c', 'd'], $slugs('position,-published_at'));
        $this->assertSame(['a', 'b', 'c', 'd'], $slugs('-position,-published_at'));
        $this->assertSame(['b', 'd', 'c', 'a'], $slugs('-featured,-published_at'));
        $this->assertSame(['c', 'a', 'b', 'd'], $slugs('-published_at'));
        $this->assertSame(['b', 'd', 'a', 'c'], $slugs('-featured,position,-published_at'), 'the order the site asks for');
    }

    public function test_the_default_order_is_the_latest_first_and_pages_do_not_cut_the_total(): void
    {
        foreach (['2026-01-01', '2026-03-01', '2026-02-01'] as $i => $date) {
            $this->entry("e{$i}", ['published_at' => "{$date} 00:00:00"]);
        }

        $first = $this->getJson('/api/v2/entries/book?language=en&per_page=2')->assertOk();
        $this->assertSame(['e1', 'e2'], array_column($first->json('data'), 'slug'));
        $first->assertJsonPath('meta', ['page' => 1, 'per_page' => 2, 'total' => 3]);

        $second = $this->getJson('/api/v2/entries/book?language=en&per_page=2&page=2')->assertOk();
        $this->assertSame(['e0'], array_column($second->json('data'), 'slug'));
    }

    /** @return array<string, array{string}> */
    public static function invalidRequests(): array
    {
        return [
            'no language' => ['/api/v2/entries/book'],
            'a bad language' => ['/api/v2/entries/book?language=Not_A_Tag'],
            'two languages' => ['/api/v2/entries/book?language[]=en'],
            'over the maximum' => ['/api/v2/entries/book?language=en&per_page=101'],
            'per_page zero' => ['/api/v2/entries/book?language=en&per_page=0'],
            'page zero' => ['/api/v2/entries/book?language=en&page=0'],
            'page not a number' => ['/api/v2/entries/book?language=en&page=x'],
            'an undeclared field' => ['/api/v2/entries/book?language=en&fields=nope'],
            'sort by an undeclared field' => ['/api/v2/entries/book?language=en&sort=nope'],
            'sort by a list' => ['/api/v2/entries/book?language=en&sort=chapters'],
            'sort by Markdown' => ['/api/v2/entries/book?language=en&sort=notes'],
            'sort by media' => ['/api/v2/entries/book?language=en&sort=cover'],
            'sort by a reference' => ['/api/v2/entries/book?language=en&sort=related'],
            'sort by several values' => ['/api/v2/entries/book?language=en&sort=tags'],
            'a detail with no language' => ['/api/v2/entries/book/dune'],
            'a bad slug' => ['/api/v2/entries/book/Not_A_Slug?language=en'],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_a_request_that_breaks_the_contract_is_refused(string $url): void
    {
        $this->getJson($url)->assertStatus(422)->assertJsonPath('code', 'invalid_request')->assertJsonStructure(['code', 'message']);
    }

    public function test_an_unknown_type_or_route_is_not_found(): void
    {
        $this->getJson('/api/v2/entries/nope?language=en')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson('/api/v2/nope')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_only_reading_is_served(): void
    {
        $this->postJson('/api/v2/entries/book?language=en')->assertStatus(405)->assertJsonPath('code', 'invalid_request');
    }

    public function test_reading_writes_nothing(): void
    {
        $this->entry('dune', ['translation_key' => 'a']);
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->getJson('/api/v2/entries/book?language=en')->assertOk();
        $this->getJson('/api/v2/entries/book/dune?language=en')->assertOk();
        $this->getJson('/api/v2/languages')->assertOk();

        $this->assertSame([], $writes);
    }

    public function test_a_database_failure_is_unavailable_and_keeps_its_details_inside(): void
    {
        Schema::rename('entries', 'entries_elsewhere');

        $response = $this->getJson('/api/v2/entries/book?language=en')->assertStatus(503)->assertJsonPath('code', 'unavailable');

        $this->assertStringNotContainsString('entries', (string) $response->getContent());
    }

    public function test_too_many_requests_say_when_to_come_back(): void
    {
        config(['gllhub.api.per_minute' => 1]);
        RateLimiter::clear('api-v2');

        $this->getJson('/api/v2/languages')->assertOk();
        $this->getJson('/api/v2/languages')->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    }

    public function test_times_are_utc_with_an_offset_even_inside_lists(): void
    {
        $this->entry('dune', [
            'published_at' => '2026-01-01 10:00:00',
            'source_updated_at' => '2026-02-01 10:00:00',
            'fields' => ['isbn' => '1', 'released' => '2026-03-01T02:00:00+02:00', 'chapters' => [['title' => 'One', 'finished_at' => '2026-04-01T10:00:00Z']]],
        ]);

        $entry = $this->getJson('/api/v2/entries/book/dune?language=en')->json();

        $this->assertSame('2026-01-01T10:00:00+00:00', $entry['published_at']);
        $this->assertSame('2026-02-01T10:00:00+00:00', $entry['updated_at']);
        $this->assertSame('2026-03-01T00:00:00+00:00', $entry['fields']['released']);
        $this->assertSame('2026-04-01T10:00:00+00:00', $entry['fields']['chapters'][0]['finished_at']);
    }

    public function test_media_is_null_until_imported_then_a_file_with_its_size(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $entry = $this->entry('dune');

        $fields = $this->getJson('/api/v2/entries/book/dune?language=en')->json('fields');
        $this->assertArrayHasKey('cover', $fields);
        $this->assertNull($fields['cover']);
        $this->assertSame([], $fields['gallery']);

        $job = (new ImportRemoteMedia($entry->id, new RemoteMedia('cover', 'https://img.test/c.png', alt: 'A cover')))->withFakeQueueInteractions();
        $job->handle($this->app->make(HttpClient::class), $this->app->make(LoggerInterface::class));
        $job = (new ImportRemoteMedia($entry->id, new RemoteMedia('gallery', 'https://img.test/g.png')))->withFakeQueueInteractions();
        $job->handle($this->app->make(HttpClient::class), $this->app->make(LoggerInterface::class));

        $fields = $this->getJson('/api/v2/entries/book/dune?language=en')->json('fields');
        $this->assertSame('A cover', $fields['cover']['alt']);
        $this->assertSame(1, $fields['cover']['width']);
        $this->assertSame(1, $fields['cover']['height']);
        $this->assertSame('image/png', $fields['cover']['mime']);
        $this->assertStringStartsWith('http', $fields['cover']['url']);
        $this->assertNull($fields['gallery'][0]['alt']);
    }

    public function test_a_file_that_is_no_image_has_no_size(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response('not an image', 200, ['Content-Type' => 'video/mp4'])]);
        $entry = $this->entry('dune');

        (new ImportRemoteMedia($entry->id, new RemoteMedia('cover', 'https://img.test/c.mp4')))->withFakeQueueInteractions()
            ->handle($this->app->make(HttpClient::class), $this->app->make(LoggerInterface::class));

        $cover = $this->getJson('/api/v2/entries/book/dune?language=en')->json('fields.cover');
        $this->assertNull($cover['width']);
        $this->assertNull($cover['height']);
    }

    public function test_text_points_at_the_copy_once_it_is_imported(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $text = '![a](https://img.test/a.png) ![b](https://img.test/b.png)';
        $entry = $this->entry('dune', ['body' => $text, 'fields' => ['isbn' => '1', 'notes' => $text, 'chapters' => [['title' => 'One', 'draft' => $text]]]]);

        (new ImportRemoteMedia($entry->id, new RemoteMedia('gallery', 'https://img.test/a.png')))->withFakeQueueInteractions()
            ->handle($this->app->make(HttpClient::class), $this->app->make(LoggerInterface::class));

        $json = $this->getJson('/api/v2/entries/book/dune?language=en')->json();
        $copy = $json['fields']['gallery'][0]['url'];

        foreach ([$json['body'], $json['fields']['notes'], $json['fields']['chapters'][0]['draft']] as $served) {
            $this->assertStringContainsString("![a]({$copy})", $served);
            $this->assertStringContainsString('![b](https://img.test/b.png)', $served, 'what is not imported yet keeps its source');
        }
    }

    public function test_placeholders_and_html_formats_reach_the_reader_intact(): void
    {
        $this->entry('dune', ['body' => "Text.\n\n<!-- gll:demo -->\n"]);
        $this->entry('emma', ['body' => '<p>Clean</p>', 'body_format' => BodyFormat::Html]);

        $this->assertSame("Text.\n\n<!-- gll:demo -->\n", $this->getJson('/api/v2/entries/book/dune?language=en')->json('body'));
        $this->getJson('/api/v2/entries/book/emma?language=en')->assertJsonPath('body_format', 'html');
    }

    public function test_references_come_in_the_language_asked_for_and_what_is_unresolved_or_unpublished_is_left_out(): void
    {
        $from = $this->entry('from');
        $target = $this->entry('target', ['translation_key' => 'a']);
        $targetIt = $this->entry('bersaglio', ['translation_key' => 'a', 'language' => 'it', 'title' => 'Bersaglio']);
        $hidden = $this->entry('hidden', ['published_at' => null]);
        $store = $this->app->make(ReferenceStore::class);
        $store->replace($from->id, [new ExternalRef('related', type: 'book', slug: 'nowhere')], ['related']);
        EntryReference::insert([
            ['entry_id' => $from->id, 'field' => 'related', 'position' => 1, 'target_entry_id' => $target->id],
            ['entry_id' => $from->id, 'field' => 'related', 'position' => 2, 'target_entry_id' => $hidden->id],
        ]);
        $store->link($from->id, 'sequel', [$target->id]);
        $this->app->make(ReferenceResolver::class)->resolve();

        $english = $this->getJson('/api/v2/entries/book/from?language=en')->json('fields');
        $this->assertSame([['type' => 'book', 'slug' => 'target', 'title' => 'Target']], $english['related']);
        $this->assertSame(['type' => 'book', 'slug' => 'target', 'title' => 'Target'], $english['sequel']);

        $italian = $this->getJson('/api/v2/entries/book/from?language=it')->json('fields');
        $this->assertSame([['type' => 'book', 'slug' => 'bersaglio', 'title' => 'Bersaglio']], $italian['related'], 'even though the link was resolved to another language');
        $this->assertSame($targetIt->slug, $italian['sequel']['slug']);
    }

    public function test_a_single_reference_with_no_target_is_null(): void
    {
        $this->entry('dune');

        $fields = $this->getJson('/api/v2/entries/book/dune?language=en')->json('fields');

        $this->assertArrayHasKey('sequel', $fields);
        $this->assertNull($fields['sequel']);
        $this->assertSame([], $fields['related']);
    }

    public function test_a_page_of_entries_does_not_query_once_per_entry(): void
    {
        foreach (range(1, 12) as $i) {
            $entry = $this->entry("e{$i}", ['translation_key' => "k{$i}"]);
            $this->app->make(ReferenceStore::class)->link($entry->id, 'sequel', [$entry->id]);
        }
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/v2/entries/book?language=en&per_page=12')->assertOk()->assertJsonCount(12, 'data');

        $this->assertLessThan(12, $queries);
    }

    public function test_a_synced_entry_is_served_too(): void
    {
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', ['publishedAt' => new \DateTimeImmutable('2026-01-01T00:00:00Z')])];
        $this->sync($this->makeInstance());

        $this->getJson('/api/v2/entries/book/dune?language=en')->assertOk()->assertJsonPath('fields.isbn', 'isbn-1');
    }
}
