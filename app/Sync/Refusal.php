<?php

declare(strict_types=1);

namespace App\Sync;

/** One entry a run saw and did not store, with the stable code that says who refused it. */
final readonly class Refusal
{
    /** @param list<string> $reasons */
    public function __construct(
        public string $code,
        public string $externalId,
        public string $language,
        public array $reasons,
    ) {
    }

    public function line(): string
    {
        return "{$this->code}: {$this->externalId} ({$this->language}): " . implode('; ', $this->reasons);
    }
}
