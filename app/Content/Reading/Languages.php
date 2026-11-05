<?php

declare(strict_types=1);

namespace App\Content\Reading;

use RuntimeException;

/** The languages an instance holds: declared in its configuration, never guessed from its content. */
final class Languages
{
    public function default(): string
    {
        $this->check();

        return (string) config('gllhub.languages.default');
    }

    /** @return list<string> */
    public function available(): array
    {
        $this->check();

        return config('gllhub.languages.available');
    }

    private function check(): void
    {
        $default = config('gllhub.languages.default');
        $available = config('gllhub.languages.available');

        if (! is_string($default) || $default === '' || ! is_array($available) || ! in_array($default, $available, true)) {
            throw new RuntimeException('Set GLLHUB_LANGUAGES and GLLHUB_DEFAULT_LANGUAGE; the default must be one of the languages.');
        }
    }
}
