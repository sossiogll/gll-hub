<?php

declare(strict_types=1);

namespace App\Content;

use App\Content\Rules\EntryValidator;
use App\Content\Rules\Slug;
use App\Models\Entry;
use App\Packages\PackageRegistry;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\FieldType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The one way a person writes an entry: a new one by hand, or changes to an existing one.
 *
 * What the person may change follows who owns it. A hand-written entry is theirs entirely. A synced entry only has
 * its curated values: the title, the body and every other field belong to the source and the next sync would
 * overwrite them, so here they are not written whatever the caller sends.
 */
final class EntryEditor
{
    public function __construct(
        private readonly PackageRegistry $registry,
        private readonly SlugAllocator $slugs,
        private readonly ReferenceStore $references,
        private readonly SharedCuration $curation,
    ) {
    }

    /** @throws InvalidEntry */
    public function create(string $type, EntryInput $input, ?int $authorId): Entry
    {
        $definition = $this->definition($type);
        $this->check($definition, $input, null, true);

        return DB::transaction(function () use ($definition, $input, $authorId): Entry {
            $entry = $this->slugs->claim(
                $definition->key,
                $input->language,
                Slug::from($input->slug ?? $input->title),
                function (string $slug) use ($definition, $input, $authorId): Entry {
                    $entry = new Entry();
                    $entry->forceFill($this->handWrittenColumns($definition, $input, $entry) + [
                        'type' => $definition->key,
                        'slug' => $slug,
                        'author_id' => $authorId,
                    ])->save();

                    return $entry;
                },
            );
            $this->link($entry, $definition, $input, true);

            return $entry;
        });
    }

    /** @throws InvalidEntry */
    public function update(Entry $entry, EntryInput $input): Entry
    {
        $definition = $this->definition($entry->type);
        $handWritten = $entry->isHandWritten();
        $this->check($definition, $input, $entry, $handWritten);

        return DB::transaction(function () use ($entry, $definition, $input, $handWritten): Entry {
            if ($handWritten) {
                $this->saveHandWritten($entry, $definition, $input);
            } else {
                $entry->forceFill(['curated' => $this->values($definition, $entry->curated, $input->curated, true)])->save();
            }
            $this->link($entry, $definition, $input, $handWritten);
            $this->curation->spreadFrom($entry, $definition);

            return $entry;
        });
    }

    private function definition(string $type): ContentTypeDefinition
    {
        return $this->registry->contentType($type) ?? throw new InvalidEntry(["content type \"{$type}\" is not installed"]);
    }

    private function check(ContentTypeDefinition $type, EntryInput $input, ?Entry $entry, bool $handWritten): void
    {
        $violations = $handWritten
            ? [...EntryValidator::handWritten($type, $input->title, $input->fields, $input->curated), ...EntryValidator::columns($input->title, $input->language)]
            : EntryValidator::curated($type, $input->curated);
        array_push($violations, ...$this->referenceViolations($type, $handWritten ? $input->fields + $input->curated : $input->curated, $entry?->id));

        if ($violations !== []) {
            throw new InvalidEntry($violations);
        }
    }

    /**
     * FieldRules only knows a reference is an id; whether an entry has that id, and is of a type the field allows,
     * needs the store.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function referenceViolations(ContentTypeDefinition $type, array $values, ?int $self): array
    {
        $violations = [];
        foreach ($type->fields as $field) {
            if ($field->type !== FieldType::Reference) {
                continue;
            }
            $ids = array_values(array_filter(Arr::wrap($values[$field->key] ?? null), 'is_int'));
            if ($ids === []) {
                continue;
            }

            $types = Entry::whereIn('id', $ids)->pluck('type', 'id');
            foreach ($ids as $id) {
                if ($id === $self) {
                    $violations[] = "field \"{$field->key}\" cannot point at the entry itself";
                } elseif (! $types->has($id)) {
                    $violations[] = "field \"{$field->key}\" points at entry {$id}, which does not exist";
                } elseif ($field->references !== [] && ! in_array($types[$id], $field->references, true)) {
                    $violations[] = "field \"{$field->key}\" points at entry {$id}, a {$types[$id]}, but only " . implode(', ', $field->references) . ' can be linked';
                }
            }
        }

        return $violations;
    }

    private function saveHandWritten(Entry $entry, ContentTypeDefinition $type, EntryInput $input): void
    {
        $columns = $this->handWrittenColumns($type, $input, $entry);
        $slug = $input->slug === null ? $entry->slug : Slug::from($input->slug);

        // A URL is chosen once: it moves only when the person says so, or when the new language already has it.
        if ($slug === $entry->slug && $input->language === $entry->language) {
            $entry->forceFill($columns)->save();

            return;
        }

        $this->slugs->claim(
            $entry->type,
            $input->language,
            $slug,
            fn (string $free): bool => $entry->forceFill($columns + ['slug' => $free])->save(),
        );
    }

    /** @return array<string, mixed> */
    private function handWrittenColumns(ContentTypeDefinition $type, EntryInput $input, Entry $current): array
    {
        return [
            'title' => $input->title,
            'summary' => $input->summary,
            'body' => $input->body,
            'language' => $input->language,
            'published_at' => $input->publishedAt?->copy()->utc(),
            'fields' => $this->values($type, $current->fields, $input->fields, false),
            'curated' => $this->values($type, $current->curated, $input->curated, true),
        ];
    }

    /**
     * What one of the two JSON columns holds after a save: the values of the fields the person edits, over whatever
     * the column held for keys the type does not declare (a field a package dropped keeps its value).
     * References and images are not values, they live in their own tables.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    private function values(ContentTypeDefinition $type, array $stored, array $new, bool $curated): array
    {
        $declared = [];
        foreach ($type->fields as $field) {
            if ($field->curated === $curated && $field->type !== FieldType::Reference && $field->type !== FieldType::Media) {
                $declared[$field->key] = true;
            }
        }

        return array_diff_key($stored, $declared) + array_intersect_key($new, $declared);
    }

    private function link(Entry $entry, ContentTypeDefinition $type, EntryInput $input, bool $handWritten): void
    {
        foreach ($type->fields as $field) {
            // A source owns its references; the person's only to the extent they curate the field.
            if ($field->type !== FieldType::Reference || (! $handWritten && ! $field->curated)) {
                continue;
            }
            $given = ($field->curated ? $input->curated : $input->fields)[$field->key] ?? null;

            $this->references->link($entry->id, $field->key, array_values(array_filter(Arr::wrap($given), 'is_int')));
        }
    }
}
