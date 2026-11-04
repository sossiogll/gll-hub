<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Package;

use DateTimeImmutable;
use Gll\Hub\Contracts\Http\HttpClient;
use Psr\Log\LoggerInterface;

/**
 * What the core gives a package at call time, and the ONLY way a package reaches
 * the core. A package never touches the core's database, queues, cache or
 * environment: it talks to its own outside service through http() and returns data.
 *
 * Settings and credentials are a snapshot taken for this call, merged in this
 * order: package-wide values, then the values of the instance being called
 * (a source instance, a webhook endpoint…).
 */
interface Context
{
    public function setting(string $key, mixed $default = null): mixed;

    /** @return array<string, mixed> */
    public function settings(): array;

    /** The decrypted secret, or null when it is not configured. */
    public function credential(string $key): ?string;

    public function http(): HttpClient;

    public function logger(): LoggerInterface;

    public function now(): DateTimeImmutable;

    /** The public base URL of the core, e.g. "https://hub.example.test". */
    public function publicUrl(): string;
}
