<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Testing;

use Gll\Hub\Contracts\Auth\AuthProvider;
use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\EntryRules;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;
use Gll\Hub\Contracts\Contracts;
use Gll\Hub\Contracts\Package\Capability;
use Gll\Hub\Contracts\Package\Credential;
use Gll\Hub\Contracts\Package\Package;
use Gll\Hub\Contracts\Package\Role;
use Gll\Hub\Contracts\Package\Setting;
use Gll\Hub\Contracts\Package\SettingType;
use Gll\Hub\Contracts\Source\ExternalEntry;
use Gll\Hub\Contracts\Source\Source;
use Gll\Hub\Contracts\Source\SourceDefinition;
use Gll\Hub\Contracts\Webhook\WebhookReceiver;

/**
 * The rules every package must follow, run by each package's own test suite:
 *
 *     Conformance::check(new MyPackage());
 *     Conformance::checkEntries(MySource::definition(), [Book::definition()], $sampleEntries);
 *
 * Both throw one ConformanceError listing every violation. A new generic rule goes
 * here, never in a package.
 */
final class Conformance
{
    private const KEY = '/^[a-z][a-z0-9_]{0,62}$/';
    private const PACKAGE_NAME = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?\/[a-z0-9]([a-z0-9-]*[a-z0-9])?$/';

    private const ROLE_CONTRACTS = [
        'content_type' => ContentType::class,
        'source' => Source::class,
        'webhook' => WebhookReceiver::class,
        'auth' => AuthProvider::class,
    ];

    public static function check(Package $package): void
    {
        $v = [];
        $m = $package->manifest();

        if (! preg_match(self::PACKAGE_NAME, $m->name)) {
            $v[] = "manifest name \"{$m->name}\" is not a vendor/package name";
        }
        if (! Contracts::accepts($m->contracts)) {
            $v[] = "manifest declares contracts {$m->contracts}, this core speaks " . Contracts::VERSION;
        }
        if ($m->provides === []) {
            $v[] = 'manifest provides nothing';
        }

        self::checkSettings($m->settings, $m->credentials, 'package', $v);

        $sourceKeys = [];
        $keysByRole = [];

        foreach ($m->provides as $role => $classes) {
            if (Role::tryFrom($role) === null) {
                $v[] = "unknown role \"{$role}\" in provides";
                continue;
            }
            foreach ($classes as $class) {
                if (! class_exists($class)) {
                    $v[] = "{$role}: class {$class} does not exist";
                    continue;
                }
                $contract = self::ROLE_CONTRACTS[$role];
                if (! is_subclass_of($class, $contract)) {
                    $v[] = "{$role}: {$class} does not implement {$contract}";
                    continue;
                }

                $definition = $class::definition();
                $key = $definition->key;
                if (! preg_match(self::KEY, $key)) {
                    $v[] = "{$class}: key \"{$key}\" must match " . self::KEY;
                }
                if (isset($keysByRole[$role][$key])) {
                    $v[] = "{$role}: key \"{$key}\" is used twice";
                }
                $keysByRole[$role][$key] = true;

                if ($definition instanceof SourceDefinition) {
                    $sourceKeys[] = $key;
                    self::checkSource($class, $definition, $v);
                } elseif ($definition instanceof ContentTypeDefinition) {
                    self::checkFields($definition->fields, "type {$key}", $v);
                } elseif ($role === 'auth') {
                    self::checkSettings($definition->settings, $definition->credentials, "auth {$key}", $v);
                }

                if ($role === 'webhook') {
                    foreach ($definition->refreshes as $refreshed) {
                        if (! in_array($refreshed, self::sourceKeysOf($m->provides), true)) {
                            $v[] = "webhook {$key} refreshes \"{$refreshed}\", which is not a source of this package";
                        }
                    }
                    self::checkSettings([], $definition->credentials, "webhook {$key}", $v);
                }
            }
        }

        self::fail($v);
    }

    /**
     * Checks sample entries a source produced against the content types they claim,
     * with the rules the core applies to every entry at sync time (Content\EntryRules).
     *
     * @param list<ContentTypeDefinition> $types
     * @param iterable<ExternalEntry> $entries
     */
    public static function checkEntries(SourceDefinition $source, array $types, iterable $entries): void
    {
        $v = [];
        foreach ($entries as $entry) {
            array_push($v, ...EntryRules::violations($source, $types, $entry));
        }

        self::fail($v);
    }

    /** @param list<string> $v */
    private static function checkSource(string $class, SourceDefinition $d, array &$v): void
    {
        if ($d->produces === []) {
            $v[] = "source {$d->key} produces no content type";
        }
        foreach (Capability::cases() as $capability) {
            $declared = $d->has($capability);
            $implemented = is_subclass_of($class, $capability->contract());
            if ($declared && ! $implemented) {
                $v[] = "source {$d->key} declares {$capability->value} but does not implement " . $capability->contract();
            }
            if ($implemented && ! $declared) {
                $v[] = "source {$d->key} implements " . $capability->contract() . " but does not declare {$capability->value}";
            }
        }
        if ($d->defaultIntervalMinutes < 0) {
            $v[] = "source {$d->key}: defaultIntervalMinutes cannot be negative";
        }
        self::checkSettings($d->instanceSettings, $d->instanceCredentials, "source {$d->key}", $v);
    }

    /**
     * @param list<Setting> $settings
     * @param list<Credential> $credentials
     * @param list<string> $v
     */
    private static function checkSettings(array $settings, array $credentials, string $where, array &$v): void
    {
        $keys = [];
        foreach ($settings as $s) {
            if (! preg_match(self::KEY, $s->key)) {
                $v[] = "{$where}: setting key \"{$s->key}\" must match " . self::KEY;
            }
            if (isset($keys[$s->key])) {
                $v[] = "{$where}: setting \"{$s->key}\" is declared twice";
            }
            $keys[$s->key] = true;
            if ($s->type === SettingType::Enum && $s->options === []) {
                $v[] = "{$where}: enum setting \"{$s->key}\" has no options";
            }
            if ($s->default !== null && ! self::defaultFits($s)) {
                $v[] = "{$where}: the default of \"{$s->key}\" is not a valid {$s->type->value}";
            }
        }
        foreach ($settings as $s) {
            foreach (array_keys($s->visibleWhen ?? []) as $other) {
                if (! isset($keys[$other])) {
                    $v[] = "{$where}: \"{$s->key}\" is visible when \"{$other}\", which is not a setting";
                }
            }
        }
        foreach ($credentials as $c) {
            if (! preg_match(self::KEY, $c->key)) {
                $v[] = "{$where}: credential key \"{$c->key}\" must match " . self::KEY;
            }
            if (isset($keys[$c->key])) {
                $v[] = "{$where}: \"{$c->key}\" is both a setting and a credential, or declared twice";
            }
            $keys[$c->key] = true;
        }
    }

    /**
     * @param list<Field> $fields
     * @param list<string> $v
     * @param bool $topLevel the core's own field names are reserved only at the top, not inside list items
     */
    private static function checkFields(array $fields, string $where, array &$v, bool $topLevel = true): void
    {
        $reserved = ['title', 'slug', 'summary', 'body', 'language', 'thumbnail', 'author', 'published_at'];
        $keys = [];
        foreach ($fields as $f) {
            if (! preg_match(self::KEY, $f->key)) {
                $v[] = "{$where}: field key \"{$f->key}\" must match " . self::KEY;
            }
            if ($topLevel && in_array($f->key, $reserved, true)) {
                $v[] = "{$where}: \"{$f->key}\" is a core field and cannot be declared";
            }
            if (isset($keys[$f->key])) {
                $v[] = "{$where}: field \"{$f->key}\" is declared twice";
            }
            $keys[$f->key] = true;
            if ($f->type === FieldType::Enum && $f->options === []) {
                $v[] = "{$where}: enum field \"{$f->key}\" has no options";
            }
            if ($f->type === FieldType::List) {
                if ($f->of === []) {
                    $v[] = "{$where}: list field \"{$f->key}\" has no item fields";
                }
                self::checkFields($f->of, "{$where}.{$f->key}", $v, topLevel: false);
            }
            if ($f->type === FieldType::Reference && $f->references === []) {
                $v[] = "{$where}: reference field \"{$f->key}\" names no content type";
            }
        }
    }

    private static function defaultFits(Setting $s): bool
    {
        return match ($s->type) {
            SettingType::String, SettingType::Text => is_string($s->default),
            SettingType::Url => is_string($s->default) && filter_var($s->default, FILTER_VALIDATE_URL) !== false,
            SettingType::Integer => is_int($s->default),
            SettingType::Boolean => is_bool($s->default),
            SettingType::Enum => in_array($s->default, $s->options, true),
            SettingType::StringList => is_array($s->default) && array_is_list($s->default),
        };
    }

    /**
     * @param array<string, list<class-string>> $provides
     * @return list<string>
     */
    private static function sourceKeysOf(array $provides): array
    {
        $keys = [];
        foreach ($provides['source'] ?? [] as $class) {
            if (class_exists($class) && is_subclass_of($class, Source::class)) {
                $keys[] = $class::definition()->key;
            }
        }

        return $keys;
    }

    /** @param list<string> $v */
    private static function fail(array $v): void
    {
        if ($v !== []) {
            throw new ConformanceError($v);
        }
    }
}
