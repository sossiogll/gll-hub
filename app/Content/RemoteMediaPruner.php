<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Entry;
use Gll\Hub\Contracts\Source\RemoteMedia;

/**
 * Removes the files a source no longer names. Without it a field that holds several files only grows: a source that
 * links a file by version names a new URL at every version. A file a person uploaded has no source URL and stays.
 */
final class RemoteMediaPruner
{
    /** @param list<RemoteMedia> $current the complete set the source names now */
    public function keepOnly(int $entryId, array $current): void
    {
        $entry = Entry::find($entryId);
        if ($entry === null) {
            return;
        }

        $kept = [];
        foreach ($current as $media) {
            $kept["{$media->field} {$media->url}"] = true;
        }

        foreach ($entry->media as $file) {
            $url = $file->getCustomProperty('source_url');
            if ($url !== null && ! isset($kept["{$file->collection_name} {$url}"])) {
                $file->delete();
            }
        }
    }
}
