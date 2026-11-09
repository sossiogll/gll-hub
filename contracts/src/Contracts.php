<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts;

/**
 * The version of the contract.
 *
 * The major is the contract: a package built on 1.x runs on any core that speaks 1.x.
 * Within a major the contract only grows: a new method is added on a new optional
 * interface (a capability), a new field on a value object always has a default.
 * Anything else is a new major.
 */
final class Contracts
{
    public const VERSION = '1.1.0';

    public static function major(): int
    {
        return (int) explode('.', self::VERSION)[0];
    }

    /** True when a package that declares $declared can run on this contract. */
    public static function accepts(string $declared): bool
    {
        $parts = explode('.', $declared);

        if (count($parts) < 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return false;
        }

        [$major, $minor] = [(int) $parts[0], (int) $parts[1]];
        [, $ownMinor] = array_map('intval', explode('.', self::VERSION));

        return $major === self::major() && $minor <= $ownMinor;
    }
}
