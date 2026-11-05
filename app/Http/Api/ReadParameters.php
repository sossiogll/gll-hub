<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Content\Reading\EntrySort;
use App\Content\Reading\SortKey;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\EntryRules;
use Illuminate\Http\Request;

/** What a read of the API asks for, or the refusal when it breaks the contract. Nothing is ever silently adjusted. */
final readonly class ReadParameters
{
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PER_PAGE = 20;

    public function __construct(private Request $request, private ContentTypeDefinition $type)
    {
    }

    public function language(): string
    {
        $language = $this->text('language') ?? throw ApiError::invalid('"language" is required.');
        if (! EntryRules::isLanguage($language)) {
            throw ApiError::invalid('"language" is not a language tag.');
        }

        return $language;
    }

    /** @return list<string>|null null = every field */
    public function fields(): ?array
    {
        $fields = $this->text('fields');
        if ($fields === null) {
            return null;
        }

        $keys = explode(',', $fields);
        foreach ($keys as $key) {
            if ($this->type->field($key) === null) {
                throw ApiError::invalid("\"fields\" names \"{$key}\", which this type does not declare.");
            }
        }

        return $keys;
    }

    /** @return list<SortKey> */
    public function sort(): array
    {
        $sort = $this->text('sort');
        if ($sort === null) {
            return [];
        }

        $keys = [];
        foreach (explode(',', $sort) as $part) {
            $descending = str_starts_with($part, '-');
            $key = $descending ? substr($part, 1) : $part;
            if (! EntrySort::accepts($this->type, $key)) {
                throw ApiError::invalid("\"sort\" cannot use \"{$key}\": only simple declared fields can sort.");
            }
            $keys[] = new SortKey($key, $descending);
        }

        return $keys;
    }

    public function page(): int
    {
        return $this->positive('page') ?? 1;
    }

    public function perPage(): int
    {
        $perPage = $this->positive('per_page') ?? self::DEFAULT_PER_PAGE;
        if ($perPage > self::MAX_PER_PAGE) {
            throw ApiError::invalid('"per_page" cannot be more than ' . self::MAX_PER_PAGE . '.');
        }

        return $perPage;
    }

    private function positive(string $name): ?int
    {
        $value = $this->text($name);
        if ($value === null) {
            return null;
        }
        if (! ctype_digit($value) || (int) $value < 1) {
            throw ApiError::invalid("\"{$name}\" must be a positive integer.");
        }

        return (int) $value;
    }

    private function text(string $name): ?string
    {
        $value = $this->request->query($name);
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw ApiError::invalid("\"{$name}\" must be a single value.");
        }

        return $value;
    }
}
