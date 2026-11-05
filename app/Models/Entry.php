<?php

declare(strict_types=1);

namespace App\Models;

use App\Packages\PackageRegistry;
use Gll\Hub\Contracts\Content\FieldType;
use Gll\Hub\Contracts\Source\BodyFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * The core's only content table: what comes from a source, what is written by
 * hand, and later what laravel-blog held.
 *
 * `fields` holds the values the source owns (a sync overwrites them), `curated`
 * the values the person owns (a sync never touches them). Images live in the media
 * library, one collection per Media field of the type plus `thumbnail`.
 *
 * @property array<string, mixed> $fields
 * @property array<string, mixed> $curated
 */
class Entry extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    public const THUMBNAIL = 'thumbnail';

    protected $fillable = [
        'type', 'slug', 'title', 'summary', 'body', 'body_format', 'language', 'translation_key',
        'fields', 'curated', 'canonical_url', 'published_at', 'author_id',
    ];

    protected $attributes = [
        'body_format' => 'markdown',
        'language' => 'en',
        'fields' => '[]',
        'curated' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'curated' => 'array',
            'body_format' => BodyFormat::class,
            'published_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * What readers may see. An entry dated in the future is not out yet.
     *
     * @param Builder<Entry> $query
     * @return Builder<Entry>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * No source instance = the person owns every column. An entry whose instance was deleted becomes
     * hand-written too: the content is kept, and nothing is left to overwrite it.
     */
    public function isHandWritten(): bool
    {
        return $this->source_instance_id === null;
    }

    /** @return BelongsTo<SourceInstance, $this> */
    public function sourceInstance(): BelongsTo
    {
        return $this->belongsTo(SourceInstance::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<EntryReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(EntryReference::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::THUMBNAIL)->singleFile();

        // The media library also asks a blank model for its collections.
        $type = $this->type === null ? null : app(PackageRegistry::class)->contentType($this->type);
        foreach ($type?->fields ?? [] as $field) {
            if ($field->type === FieldType::Media) {
                $collection = $this->addMediaCollection($field->key);
                if (! $field->multiple) {
                    $collection->singleFile();
                }
            }
        }
    }
}
