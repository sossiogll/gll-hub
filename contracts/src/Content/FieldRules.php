<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Content;

use DateTimeImmutable;
use Throwable;

/**
 * Whether a value fits the field that declares it. One implementation for every
 * place that holds field values: the conformance check, the core when a source's
 * entry arrives, and the core when a person edits curated fields.
 *
 * Media and Reference fields hold ids of the core's own records (media library
 * items, entries), so here they are integers; a source never sends them in
 * ExternalEntry::$fields (see EntryRules).
 */
final class FieldRules
{
    /**
     * Problems with $value for $field, each phrased to follow a subject
     * ("field "x" ..."). A null value is "no value": use missing() for required ones.
     *
     * @return list<string>
     */
    public static function violations(Field $field, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if ($field->multiple && $field->type !== FieldType::List && $field->type !== FieldType::Group) {
            if (! is_array($value) || ! array_is_list($value)) {
                return ["field \"{$field->key}\" must be a list of values"];
            }
            $v = [];
            foreach ($value as $i => $item) {
                array_push($v, ...self::one($field, $item, "{$field->key}[{$i}]"));
            }

            return $v;
        }

        return self::one($field, $value, $field->key);
    }

    /**
     * The required fields among $fields that have no value in $values. Media and
     * Reference fields are left out: their values live in the core's media library
     * and reference table, not among the values.
     *
     * @param list<Field> $fields
     * @param array<string, mixed> $values
     * @return list<string>
     */
    public static function missing(array $fields, array $values): array
    {
        $v = [];
        foreach ($fields as $field) {
            $value = $values[$field->key] ?? null;
            if ($field->type === FieldType::Media || $field->type === FieldType::Reference) {
                continue;
            }
            if ($field->required && ($value === null || $value === '' || $value === [])) {
                $v[] = "required field \"{$field->key}\" is missing";
            }
        }

        return $v;
    }

    /** @return list<string> */
    private static function one(Field $field, mixed $value, string $at): array
    {
        $bad = static fn (string $what): array => ["field \"{$at}\" {$what}"];

        switch ($field->type) {
            case FieldType::String:
            case FieldType::Text:
            case FieldType::Markdown:
                if (! is_string($value)) {
                    return $bad('must be a string');
                }

                return $field->maxLength !== null && mb_strlen($value) > $field->maxLength
                    ? $bad("is longer than {$field->maxLength} characters")
                    : [];

            case FieldType::Color:
                return is_string($value) ? [] : $bad('must be a string');

            case FieldType::Url:
                return is_string($value) && self::isHttpUrl($value) ? [] : $bad('must be an http(s) URL');

            case FieldType::Integer:
            case FieldType::Media:
            case FieldType::Reference:
                return is_int($value) ? [] : $bad('must be an integer');

            case FieldType::Boolean:
                return is_bool($value) ? [] : $bad('must be a boolean');

            case FieldType::Date:
                return is_string($value) && self::isDate($value) ? [] : $bad('must be an ISO 8601 date');

            case FieldType::Enum:
                return in_array($value, $field->options, true) ? [] : $bad('has "' . (is_scalar($value) ? $value : get_debug_type($value)) . '", not one of its options');

            case FieldType::List:
                return self::items($field, $value, $at);

            case FieldType::Group:
                return self::group($field, $value, $at);
        }
    }

    /** @return list<string> */
    private static function items(Field $field, mixed $value, string $at): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return ["field \"{$at}\" must be a list of items"];
        }

        $v = [];
        foreach ($value as $i => $item) {
            array_push($v, ...self::object($field, $item, "{$at}[{$i}]", "item {$i} of \"{$at}\": ", 'item fields', "an item with named values"));
        }

        return $v;
    }

    /** @return list<string> */
    private static function group(Field $field, mixed $value, string $at): array
    {
        // An empty object is no value, so it owes nothing to the required fields inside; missing() judges a required group.
        if ($value === []) {
            return [];
        }

        return self::object($field, $value, $at, "field \"{$at}\": ", 'fields', 'an object with named values');
    }

    /**
     * The one check of an object whose keys are the fields in $holder->of: the item of a list and the value of a group.
     *
     * @param string $prefix goes before each problem found inside, to say where it is
     * @return list<string>
     */
    private static function object(Field $holder, mixed $object, string $at, string $prefix, string $fieldsName, string $expected): array
    {
        if (! is_array($object) || array_is_list($object) && $object !== []) {
            return ["field \"{$at}\" must be {$expected}"];
        }

        $v = [];
        foreach ($object as $key => $value) {
            $inner = self::inner($holder, (string) $key);
            if ($inner === null) {
                $v[] = "field \"{$at}\" has \"{$key}\", which is not in its {$fieldsName}";
                continue;
            }
            foreach (self::violations($inner, $value) as $problem) {
                $v[] = $prefix . $problem;
            }
        }
        foreach (self::missing($holder->of, $object) as $problem) {
            $v[] = $prefix . $problem;
        }

        return $v;
    }

    private static function inner(Field $list, string $key): ?Field
    {
        foreach ($list->of as $inner) {
            if ($inner->key === $key) {
                return $inner;
            }
        }

        return null;
    }

    private static function isHttpUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    private static function isDate(string $value): bool
    {
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            return false;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }
}
