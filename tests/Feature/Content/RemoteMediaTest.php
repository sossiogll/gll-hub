<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Content\ImportRemoteMedia;
use App\Models\Entry;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Http\HttpClient;
use Gll\Hub\Contracts\Source\RemoteMedia;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\Entries;
use Tests\HubTestCase;

final class RemoteMediaTest extends HubTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function import(Entry $entry, RemoteMedia $media): ImportRemoteMedia
    {
        $job = (new ImportRemoteMedia($entry->id, $media))->withFakeQueueInteractions();
        $job->handle($this->app->make(HttpClient::class), $this->app->make(LoggerInterface::class));

        return $job;
    }

    private function entry(): Entry
    {
        return Entry::create(['type' => 'book', 'slug' => 'dune', 'title' => 'Dune']);
    }

    public function test_a_sync_queues_one_download_per_file_and_only_when_the_entry_is_written(): void
    {
        Queue::fake();
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', ['media' => [
            new RemoteMedia('cover', 'https://img.test/cover.png', alt: 'Cover'),
            new RemoteMedia('thumbnail', 'https://img.test/thumb.png', headers: ['Authorization' => 'token x']),
        ]])];

        $this->sync($instance);
        $this->sync($instance);

        Queue::assertPushed(ImportRemoteMedia::class, 2);
        Queue::assertPushed(ImportRemoteMedia::class, fn (ImportRemoteMedia $job) => $job->media->field === 'thumbnail'
            && $job->media->headers === ['Authorization' => 'token x']
            && $job->entryId === Entry::sole()->id);
    }

    public function test_a_file_the_source_no_longer_names_goes_and_a_file_a_person_uploaded_stays(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $instance = $this->makeInstance();
        $version = fn (string $url, string $fingerprint) => Entries::book('1', 'Dune', ['media' => [new RemoteMedia('gallery', $url)], 'fingerprint' => $fingerprint]);

        $this->script->entries['catalog'] = [$version('https://img.test/v1/a.png', 'one')];
        $this->sync($instance);
        $entry = Entry::sole();
        $entry->addMediaFromString(base64_decode(self::PNG))->usingFileName('mine.png')->toMediaCollection('gallery');
        $this->import($entry, new RemoteMedia('gallery', 'https://img.test/v1/a.png'));
        $this->assertCount(2, $entry->fresh()->getMedia('gallery'));

        $this->script->entries['catalog'] = [$version('https://img.test/v2/a.png', 'two')];
        $this->sync($instance);

        $urls = $entry->fresh()->getMedia('gallery')->map(fn ($m) => $m->getCustomProperty('source_url'))->all();
        $this->assertNotContains('https://img.test/v1/a.png', $urls);
        $this->assertContains(null, $urls, 'the file the person uploaded has no source URL and stays');
    }

    public function test_an_unchanged_entry_keeps_its_files(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune', ['media' => [new RemoteMedia('gallery', 'https://img.test/a.png')], 'fingerprint' => 'same'])];
        $this->sync($instance);
        $this->import(Entry::sole(), new RemoteMedia('gallery', 'https://img.test/a.png'));

        $this->sync($instance);

        $this->assertCount(1, Entry::sole()->getMedia('gallery'));
    }

    public function test_the_job_travels_encrypted_because_its_headers_may_hold_a_token(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, new ImportRemoteMedia(1, new RemoteMedia('cover', 'https://img.test/c.png')));
    }

    public function test_the_file_lands_in_the_media_library_collection_of_its_field(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $entry = $this->entry();

        $this->import($entry, new RemoteMedia('cover', 'https://img.test/cover', alt: 'A cover'));

        $media = $entry->fresh()->getFirstMedia('cover');
        $this->assertNotNull($media);
        $this->assertSame('cover.png', $media->file_name, 'the extension comes from the content type when the URL has none');
        $this->assertSame('https://img.test/cover', $media->getCustomProperty('source_url'));
        $this->assertSame('A cover', $media->getCustomProperty('alt'));
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_the_same_source_url_is_downloaded_once(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $entry = $this->entry();
        $media = new RemoteMedia('gallery', 'https://img.test/a.png');

        $this->import($entry, $media);
        $this->import($entry, $media);

        $this->assertCount(1, $entry->fresh()->getMedia('gallery'));
        Http::assertSentCount(1);
    }

    public function test_a_single_file_field_is_replaced_when_the_source_url_changes_and_a_multiple_one_grows(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $entry = $this->entry();

        foreach (['a', 'b'] as $name) {
            $this->import($entry, new RemoteMedia('cover', "https://img.test/{$name}.png"));
            $this->import($entry, new RemoteMedia('gallery', "https://img.test/{$name}.png"));
        }

        $entry = $entry->fresh();
        $this->assertSame(['https://img.test/b.png'], $entry->getMedia('cover')->map(fn ($m) => $m->getCustomProperty('source_url'))->all());
        $this->assertCount(2, $entry->getMedia('gallery'));
    }

    public function test_the_thumbnail_is_a_collection_of_every_type(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $entry = $this->entry();

        $this->import($entry, new RemoteMedia('thumbnail', 'https://img.test/t.png'));

        $this->assertNotNull($entry->fresh()->getFirstMedia('thumbnail'));
    }

    public function test_request_headers_are_sent_with_the_download(): void
    {
        Storage::fake('public');
        Http::fake(['https://img.test/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);

        $this->import($this->entry(), new RemoteMedia('cover', 'https://img.test/c.png', headers: ['Authorization' => 'token abc']));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'token abc'));
    }

    public function test_a_missing_or_oversized_file_is_skipped_without_failing(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://img.test/gone.png' => Http::response('', 404),
            'https://img.test/huge.png' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
        ]);
        config(['gllhub.media.max_bytes' => 10]);
        $entry = $this->entry();

        $this->import($entry, new RemoteMedia('cover', 'https://img.test/gone.png'));
        $this->import($entry, new RemoteMedia('gallery', 'https://img.test/huge.png'));

        $this->assertCount(0, $entry->fresh()->media);
    }

    public function test_an_entry_deleted_in_the_meantime_is_ignored(): void
    {
        Http::fake();

        (new ImportRemoteMedia(999, new RemoteMedia('cover', 'https://img.test/c.png')))
            ->handle($this->app->make(HttpClient::class), $this->app->make(LoggerInterface::class));

        Http::assertNothingSent();
    }

    public function test_rate_limiting_releases_the_job_for_as_long_as_asked(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '90'])]);

        $this->import($this->entry(), new RemoteMedia('cover', 'https://img.test/c.png'))->assertReleased(90);
    }

    public function test_an_unavailable_service_is_left_to_the_queue_to_retry_with_its_backoff(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->expectException(Unavailable::class);

        $this->import($this->entry(), new RemoteMedia('cover', 'https://img.test/c.png'));
    }

    public function test_refused_credentials_skip_the_file(): void
    {
        Http::fake(['*' => Http::response('', 403)]);
        $entry = $this->entry();

        $job = $this->import($entry, new RemoteMedia('cover', 'https://img.test/c.png'));

        $job->assertNotReleased();
        $this->assertCount(0, $entry->fresh()->media);
    }
}
