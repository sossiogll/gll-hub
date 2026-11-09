<?php

declare(strict_types=1);

namespace App\Filament\Fields;

use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;
use Gll\Hub\Contracts\Package\Setting;
use Gll\Hub\Contracts\Package\SettingType;

/**
 * Turns what a form holds into what the contract's rules read. A form keeps a blank input as "", a number as text
 * and the items of a repeater under random keys; the rules, and the store, want null, integers and lists. It
 * coerces and never judges: a value that is still wrong afterwards is for the rules to refuse.
 */
final class FormValues
{
    /**
     * @param list<Field> $fields
     * @param array<string, mixed> $state
     * @return array<string, mixed> the declared fields that have a value
     */
    public static function fields(array $fields, array $state): array
    {
        $values = [];
        foreach ($fields as $field) {
            $value = self::field($field, $state[$field->key] ?? null);
            if ($value !== null) {
                $values[$field->key] = $value;
            }
        }

        return $values;
    }

    /**
     * @param list<Setting> $settings
     * @param array<string, mixed> $state
     * @return array<string, mixed> the declared settings that have a value
     */
    public static function settings(array $settings, array $state): array
    {
        $values = [];
        foreach ($settings as $setting) {
            $value = $state[$setting->key] ?? null;
            $value = match ($setting->type) {
                SettingType::Integer => self::integer($value),
                SettingType::StringList => self::list($value),
                default => self::blank($value),
            };
            if ($value !== null) {
                $values[$setting->key] = $value;
            }
        }

        return $values;
    }

    public static function integer(mixed $value): mixed
    {
        $value = self::blank($value);

        return is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1 ? (int) $value : $value;
    }

    private static function field(Field $field, mixed $value): mixed
    {
        if ($field->type === FieldType::List) {
            return self::items($field, $value);
        }
        if ($field->type === FieldType::Group) {
            $values = is_array($value) ? self::fields($field->of, $value) : [];

            return $values === [] ? null : $values;
        }
        if ($field->multiple) {
            if (! is_array($value)) {
                return self::one($field, $value);
            }
            $items = array_values(array_filter(array_map(fn (mixed $item): mixed => self::one($field, $item), $value), static fn (mixed $item): bool => $item !== null));

            return $items === [] ? null : $items;
        }

        return self::one($field, $value);
    }

    private static function one(Field $field, mixed $value): mixed
    {
        return match ($field->type) {
            FieldType::Integer, FieldType::Reference, FieldType::Media => self::integer($value),
            default => self::blank($value),
        };
    }

    private static function items(Field $field, mixed $value): ?array
    {
        $items = [];
        foreach (is_array($value) ? array_values($value) : [] as $item) {
            $values = is_array($item) ? self::fields($field->of, $item) : [];
            if ($values !== []) {
                $items[] = $values;
            }
        }

        return $items === [] ? null : $items;
    }

    private static function blank(mixed $value): mixed
    {
        return $value === '' || $value === [] ? null : $value;
    }

    /** @return list<string>|null */
    private static function list(mixed $value): ?array
    {
        $items = array_values(array_filter(is_array($value) ? $value : [], static fn (mixed $item): bool => is_string($item) && $item !== ''));

        return $items === [] ? null : $items;
    }
}
