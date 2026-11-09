<?php

declare(strict_types=1);

namespace App\Content\Reading;

use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Orders entries by what a type declares. Field keys reach the SQL as text, which is safe because only keys a package
 * declared get here, and those match a closed pattern (Conformance). Empty values come last in both directions.
 */
final class EntrySort
{
    /** The core's own columns that can sort, as the API names them. */
    private const COLUMNS = ['published_at' => 'published_at', 'title' => 'title', 'slug' => 'slug', 'updated_at' => 'COALESCE(source_updated_at, updated_at)'];
    private const NOT_SORTABLE = [FieldType::List, FieldType::Group, FieldType::Markdown, FieldType::Media, FieldType::Reference];

    public static function accepts(ContentTypeDefinition $type, string $key): bool
    {
        if (isset(self::COLUMNS[$key])) {
            return true;
        }
        $field = $type->field($key);

        return $field !== null && ! $field->multiple && ! in_array($field->type, self::NOT_SORTABLE, true);
    }

    /**
     * @param Builder<\App\Models\Entry> $query
     * @param list<SortKey> $keys
     * @return Builder<\App\Models\Entry>
     */
    public static function apply(Builder $query, ContentTypeDefinition $type, array $keys): Builder
    {
        $mysql = $query->getConnection()->getDriverName() !== 'sqlite';

        foreach ($keys === [] ? [new SortKey('published_at', true)] : $keys as $key) {
            $expression = self::COLUMNS[$key->field] ?? self::fieldExpression($type->field($key->field), $mysql);
            $query->orderByRaw("({$expression}) IS NULL")->orderByRaw("{$expression} " . ($key->descending ? 'DESC' : 'ASC'));
        }

        return $query->orderBy('id');
    }

    private static function fieldExpression(Field $field, bool $mysql): string
    {
        $column = $field->curated ? 'curated' : 'fields';
        $path = "'$.{$field->key}'";
        $value = $mysql ? "NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$column}, {$path})), 'null')" : "json_extract({$column}, {$path})";

        return match ($field->type) {
            FieldType::Integer => "CAST({$value} AS " . ($mysql ? 'SIGNED' : 'INTEGER') . ')',
            FieldType::Boolean => $mysql ? "({$value} = 'true')" : $value,
            default => $value,
        };
    }
}
