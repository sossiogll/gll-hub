<?php

declare(strict_types=1);

namespace App\Filament\Fields;

use App\Models\Entry;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;
use Gll\Hub\Contracts\Package\Credential;
use Gll\Hub\Contracts\Package\Setting;
use Gll\Hub\Contracts\Package\SettingType;

/**
 * The one place a declaration becomes a form component: a content type's Field, a package's Setting or Credential.
 * No screen draws a field of its own, so a package that declares a new field gets its input without the admin
 * knowing the package.
 *
 * Components carry hints for the person (labels, help, a maximum length, a required mark) but no validation rules:
 * what is valid is decided by the contract's rules, in the core, for every way an entry gets in.
 */
final class FieldFactory
{
    /** Entries offered in a Reference field before the person searches. */
    private const REFERENCE_CHOICES = 50;

    /** Where the form keeps the value of a content type's field: its three owners are separate state. */
    public static function path(Field $field): string
    {
        return match (true) {
            $field->type === FieldType::Media => "media.{$field->key}",
            $field->curated => "curated.{$field->key}",
            default => "fields.{$field->key}",
        };
    }

    public static function field(Field $field, bool $readOnly = false): Component
    {
        return self::decorate(self::component($field, self::path($field)), $field, $readOnly);
    }

    /**
     * @param list<Setting> $settings
     * @return list<Component>
     */
    public static function settings(array $settings, string $path = 'settings'): array
    {
        $watched = [];
        foreach ($settings as $setting) {
            array_push($watched, ...array_keys($setting->visibleWhen ?? []));
        }

        return array_map(static fn (Setting $setting): Component => self::setting($setting, $path, in_array($setting->key, $watched, true)), $settings);
    }

    /**
     * @param list<Credential> $credentials
     * @param list<string> $stored keys that already have a secret
     * @return list<Component>
     */
    public static function credentials(array $credentials, array $stored = [], string $path = 'credentials'): array
    {
        return array_map(static fn (Credential $credential): Component => self::credential($credential, in_array($credential->key, $stored, true), $path), $credentials);
    }

    private static function setting(Setting $setting, string $path, bool $watched): Component
    {
        $name = "{$path}.{$setting->key}";
        $component = match ($setting->type) {
            SettingType::Text => Textarea::make($name)->rows(3),
            SettingType::Integer => TextInput::make($name)->type('number'),
            SettingType::Boolean => Toggle::make($name),
            SettingType::Url => TextInput::make($name)->type('url'),
            SettingType::Enum => Select::make($name)->options(array_combine($setting->options, $setting->options)),
            SettingType::StringList => TagsInput::make($name),
            SettingType::String => TextInput::make($name),
        };

        $component->label($setting->label)->helperText($setting->help !== '' ? $setting->help : null);
        if ($setting->default !== null) {
            $component->default($setting->default);
        }
        if ($setting->required && $setting->type !== SettingType::Boolean) {
            $component->required();
        }
        if ($setting->visibleWhen !== null) {
            $when = $setting->visibleWhen;
            $component->visible(static function (Get $get) use ($when, $path): bool {
                foreach ($when as $key => $expected) {
                    if ((string) $get("{$path}.{$key}") !== (string) $expected) {
                        return false;
                    }
                }

                return true;
            });
        }
        if ($watched) {
            $component->live(onBlur: $setting->type === SettingType::String || $setting->type === SettingType::Integer);
        }

        return $component;
    }

    private static function credential(Credential $credential, bool $stored, string $path): Component
    {
        return TextInput::make("{$path}.{$credential->key}")
            ->label($credential->label)
            ->helperText($credential->help !== '' ? $credential->help : null)
            ->password()
            ->autocomplete('new-password')
            // Write-only: the stored secret never reaches the page, so there is nothing to retype to keep it.
            ->placeholder($stored ? 'Stored. Leave blank to keep it.' : null)
            ->required($credential->required && ! $stored);
    }

    private static function component(Field $field, string $name): Component
    {
        if ($field->type === FieldType::Group) {
            return Fieldset::make($field->label)
                ->statePath($name)
                ->schema(self::inner($field));
        }
        if ($field->type === FieldType::List) {
            return Repeater::make($name)
                ->schema(self::inner($field))
                ->defaultItems(0)
                ->collapsible()
                ->itemLabel(static fn (array $state): ?string => collect($state)->first(static fn (mixed $value): bool => is_string($value) && $value !== ''));
        }

        $listedAsItems = $field->multiple && ! in_array($field->type, [FieldType::String, FieldType::Enum, FieldType::Reference, FieldType::Media], true);
        if ($listedAsItems) {
            return Repeater::make($name)
                ->simple(self::single($field, 'value'))
                ->defaultItems(0);
        }

        return self::single($field, $name);
    }

    /** @return list<Component> the fields of a list's item or of a group, drawn as any other field */
    private static function inner(Field $holder): array
    {
        return array_map(static fn (Field $inner): Component => self::decorate(self::component($inner, $inner->key), $inner, false), $holder->of);
    }

    private static function single(Field $field, string $name): Component
    {
        return match ($field->type) {
            FieldType::String => $field->multiple
                ? TagsInput::make($name)
                : TextInput::make($name)->extraInputAttributes(self::limit($field)),
            FieldType::Text => Textarea::make($name)->rows(4)->extraInputAttributes(self::limit($field)),
            FieldType::Markdown => MarkdownEditor::make($name),
            FieldType::Url => TextInput::make($name)->type('url'),
            FieldType::Color => ColorPicker::make($name),
            FieldType::Integer => TextInput::make($name)->type('number')->extraInputAttributes(['step' => 1]),
            FieldType::Boolean => Toggle::make($name),
            FieldType::Date => DatePicker::make($name),
            FieldType::Enum => Select::make($name)->options(array_combine($field->options, $field->options))->multiple($field->multiple),
            FieldType::Reference => self::reference($field, $name),
            FieldType::Media => SpatieMediaLibraryFileUpload::make($name)->collection($field->key)->multiple($field->multiple)->reorderable($field->multiple),
            // A list and a group are handled by component(); they only reach here by mistake.
            FieldType::List, FieldType::Group => TextInput::make($name),
        };
    }

    private static function reference(Field $field, string $name): Select
    {
        $choices = static fn (string $search): array => Entry::query()
            ->when($field->references !== [], static fn ($query) => $query->whereIn('type', $field->references))
            ->when($search !== '', static fn ($query) => $query->where('title', 'like', '%' . addcslashes($search, '%_\\') . '%'))
            ->orderBy('title')
            ->limit(self::REFERENCE_CHOICES)
            ->pluck('title', 'id')
            ->all();

        return Select::make($name)
            ->multiple($field->multiple)
            ->searchable()
            ->options(static fn (): array => $choices(''))
            ->getSearchResultsUsing($choices)
            ->getOptionLabelUsing(static fn (mixed $value): ?string => Entry::query()->whereKey($value)->value('title'))
            ->getOptionLabelsUsing(static fn (array $values): array => Entry::query()->whereIn('id', $values)->pluck('title', 'id')->all());
    }

    /** @return array<string, int> */
    private static function limit(Field $field): array
    {
        return $field->maxLength === null ? [] : ['maxlength' => $field->maxLength];
    }

    private static function decorate(Component $component, Field $field, bool $readOnly): Component
    {
        $component->label($field->label);
        // A fieldset has no place for help text.
        if ($field->type !== FieldType::Group) {
            $component->helperText($field->help !== '' ? $field->help : null);
        }

        // Required Media and Reference fields are not enforced by the rules, so they are not promised here either.
        $required = $field->required && ! in_array($field->type, [FieldType::Boolean, FieldType::Media, FieldType::Reference], true);
        $component->markAsRequired($required);

        if (in_array($field->type, [FieldType::Text, FieldType::Markdown, FieldType::List, FieldType::Group, FieldType::Media], true) || ($field->multiple && $field->type !== FieldType::Enum)) {
            $component->columnSpanFull();
        }
        if ($readOnly) {
            $component->disabled()->dehydrated(false);
        }

        return $component;
    }
}
