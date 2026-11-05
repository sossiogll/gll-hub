<?php

declare(strict_types=1);

namespace App\Content\Reading;

use DateTimeImmutable;
use DateTimeZone;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;
use Throwable;

/** A stored value as the API serves it, shaped by the field that declares it, at any depth. */
final class FieldValue
{
    /**
     * @param array<string, string> $copies the media library's URL of each file, by the URL it came from
     * @return mixed null = leave the key out
     */
    public static function shape(Field $field, mixed $value, bool $full, array $copies): mixed
    {
        if ($value === null || (! $full && $field->type === FieldType::Markdown)) {
            return null;
        }

        return match ($field->type) {
            FieldType::Date => self::time($value),
            FieldType::Markdown => is_string($value) ? strtr($value, $copies) : $value,
            FieldType::List => is_array($value) ? array_map(static fn (mixed $item): array => self::item($field, (array) $item, $full, $copies), $value) : $value,
            default => $value,
        };
    }

    /** UTC with an explicit offset, whatever the source wrote. */
    public static function time(mixed $value): mixed
    {
        try {
            return (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $copies
     * @return array<string, mixed>
     */
    private static function item(Field $list, array $item, bool $full, array $copies): array
    {
        $shaped = [];
        foreach ($list->of as $inner) {
            $value = self::shape($inner, $item[$inner->key] ?? null, $full, $copies);
            if ($value !== null) {
                $shaped[$inner->key] = $value;
            }
        }

        return $shaped;
    }
}
