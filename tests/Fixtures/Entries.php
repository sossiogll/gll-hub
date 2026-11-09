<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Support\Fingerprint;

final class Entries
{
    /** @param array<string, mixed> $overrides named arguments of ExternalEntry */
    public static function book(string $id, string $title = 'A book', array $overrides = []): ExternalEntry
    {
        $arguments = $overrides + [
            'externalId' => $id,
            'type' => 'book',
            'title' => $title,
            'fields' => ['isbn' => "isbn-{$id}"],
        ];
        // An explicit null stays null: a source may have no fingerprint.
        if (! array_key_exists('fingerprint', $arguments)) {
            $arguments['fingerprint'] = Fingerprint::of($arguments['title'], json_encode($arguments['fields']), $arguments['body'] ?? null);
        }

        return new ExternalEntry(...$arguments);
    }

    public static function refused(string $id, string $reason, string $language = 'en'): ExternalEntry
    {
        return new ExternalEntry(externalId: $id, type: 'book', title: '', language: $language, refusal: $reason);
    }
}
