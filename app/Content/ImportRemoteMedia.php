<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Entry;
use Gll\Hub\Contracts\Exceptions\InvalidCredentials;
use Gll\Hub\Contracts\Exceptions\RateLimited;
use Gll\Hub\Contracts\Http\HttpClient;
use Gll\Hub\Contracts\Source\RemoteMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\MimeTypes;

/**
 * Downloads one file a source pointed at into the entry's media library, so the
 * site never serves it from the outside service. The file is told apart by its
 * source URL (a custom property): an entry synced again does not download it twice.
 *
 * Encrypted in the queue, because a RemoteMedia may carry a token in its headers.
 */
final class ImportRemoteMedia implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $entryId,
        public readonly RemoteMedia $media,
    ) {
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(HttpClient $http, LoggerInterface $logger): void
    {
        $entry = Entry::find($this->entryId);
        if ($entry === null) {
            return;
        }

        $url = $this->media->url;
        foreach ($entry->getMedia($this->media->field) as $existing) {
            if ($existing->getCustomProperty('source_url') === $url) {
                return;
            }
        }

        try {
            // Unavailable is left to bubble up: the queue retries it with the backoff above.
            $response = $http->get($url, $this->media->headers);
        } catch (RateLimited $e) {
            $this->release($e->retryAfter);

            return;
        } catch (InvalidCredentials) {
            $logger->warning('Media refused the credentials; skipped', ['entry' => $this->entryId, 'url' => strtok($url, '?#')]);

            return;
        }

        $tooBig = strlen($response->body) > (int) config('gllhub.media.max_bytes');
        if (! $response->ok() || $tooBig) {
            $logger->warning('Media skipped', ['entry' => $this->entryId, 'url' => strtok($url, '?#'), 'status' => $response->status, 'too_big' => $tooBig]);

            return;
        }

        // Readers need the size to keep the page from jumping; what is not an image has none.
        $size = @getimagesizefromstring($response->body) ?: [null, null];

        $entry->addMediaFromString($response->body)
            ->usingFileName($this->fileName($response->header('content-type')))
            ->withCustomProperties(['source_url' => $url, 'alt' => $this->media->alt, 'width' => $size[0], 'height' => $size[1]])
            ->toMediaCollection($this->media->field);
    }

    private function fileName(?string $contentType): string
    {
        $name = $this->media->filename ?? basename((string) parse_url($this->media->url, PHP_URL_PATH));
        if ($name === '' || $name === '.') {
            $name = 'media';
        }
        if (pathinfo($name, PATHINFO_EXTENSION) === '' && $contentType !== null) {
            $extension = MimeTypes::getDefault()->getExtensions(trim(explode(';', $contentType)[0]))[0] ?? null;
            $name .= $extension === null ? '' : ".{$extension}";
        }

        return $name;
    }
}
