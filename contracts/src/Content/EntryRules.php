<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Content;

use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Source\SourceDefinition;

/**
 * What makes an ExternalEntry acceptable for the content type it claims. The
 * conformance check runs it on a package's samples; the core runs the very same
 * rules on every entry a source returns, so a package that passes its tests
 * cannot be refused for a reason its tests could not see. An entry that carries a
 * refusal is judged on its identity and its reason only.
 */
final class EntryRules
{
    /** The limits of the core's columns: an entry beyond them could not be stored. */
    public const MAX_EXTERNAL_ID = 255;
    public const MAX_TITLE = 500;
    public const MAX_TRANSLATION_KEY = 255;
    private const LANGUAGE = '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/';

    /** Whether $language is a tag such as "en" or "pt-BR" that fits the core's column; the core asks it of entries written by hand too. */
    public static function isLanguage(string $language): bool
    {
        return preg_match(self::LANGUAGE, $language) === 1 && strlen($language) <= 12;
    }

    /**
     * @param list<ContentTypeDefinition> $types every type known to the caller, not only the produced ones
     * @return list<string> empty = the entry is acceptable
     */
    public static function violations(SourceDefinition $source, array $types, ExternalEntry $entry): array
    {
        $at = "entry {$entry->externalId}";
        $v = [];

        if ($entry->externalId === '') {
            $v[] = 'an entry has an empty externalId';
        } elseif (strlen($entry->externalId) > self::MAX_EXTERNAL_ID) {
            $v[] = "{$at}: externalId is longer than " . self::MAX_EXTERNAL_ID . ' bytes';
        }
        if (! in_array($entry->type, $source->produces, true)) {
            return [...$v, "{$at}: type \"{$entry->type}\" is not in the source's produces"];
        }

        if (! self::isLanguage($entry->language)) {
            $v[] = "{$at}: language \"{$entry->language}\" is not a language tag such as \"en\" or \"pt-BR\"";
        }
        if ($entry->refusal !== null) {
            // A refused entry is only an identity and a reason: its title and fields are not meant to be valid.
            return trim($entry->refusal) === '' ? [...$v, "{$at}: the refusal has no reason"] : $v;
        }

        $type = null;
        foreach ($types as $candidate) {
            if ($candidate->key === $entry->type) {
                $type = $candidate;
                break;
            }
        }
        if ($type === null) {
            return [...$v, "{$at}: no definition given for type \"{$entry->type}\""];
        }

        if (trim($entry->title) === '') {
            $v[] = "{$at}: empty title";
        } elseif (mb_strlen($entry->title) > self::MAX_TITLE) {
            $v[] = "{$at}: title is longer than " . self::MAX_TITLE . ' characters';
        }
        if ($entry->translationKey !== null && strlen($entry->translationKey) > self::MAX_TRANSLATION_KEY) {
            $v[] = "{$at}: translationKey is longer than " . self::MAX_TRANSLATION_KEY . ' bytes';
        }

        foreach ($entry->fields as $key => $value) {
            $field = $type->field((string) $key);
            if ($field === null) {
                $v[] = "{$at}: field \"{$key}\" is not in type {$type->key}";
            } elseif ($field->curated) {
                $v[] = "{$at}: field \"{$key}\" is curated, a source never sends it";
            } elseif ($field->type === FieldType::Media) {
                $v[] = "{$at}: field \"{$key}\" is a Media field, send it as media";
            } elseif ($field->type === FieldType::Reference) {
                $v[] = "{$at}: field \"{$key}\" is a Reference field, send it as references";
            } else {
                foreach (FieldRules::violations($field, $value) as $problem) {
                    $v[] = "{$at}: {$problem}";
                }
            }
        }
        $owned = array_values(array_filter($type->fields, static fn (Field $f): bool => ! $f->curated));
        foreach (FieldRules::missing($owned, $entry->fields) as $problem) {
            $v[] = "{$at}: {$problem}";
        }

        foreach ($entry->media as $media) {
            $field = $type->field($media->field);
            if ($media->field !== 'thumbnail' && ($field === null || $field->type !== FieldType::Media)) {
                $v[] = "{$at}: media for \"{$media->field}\", which is not a Media field";
            }
            if (! str_starts_with($media->url, 'https://')) {
                $v[] = "{$at}: media url must be https";
            }
        }
        foreach ($entry->references as $ref) {
            $field = $type->field($ref->field);
            if ($field === null || $field->type !== FieldType::Reference) {
                $v[] = "{$at}: reference for \"{$ref->field}\", which is not a Reference field";
            }
            if ($ref->externalId === null && ($ref->type === null || $ref->slug === null)) {
                $v[] = "{$at}: a reference needs an externalId or a type and a slug";
            }
        }

        return $v;
    }
}
