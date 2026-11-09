<?php

declare(strict_types=1);

namespace App\Sync;

use App\Content\WriteOutcome;

/** What one run did, for the logs and for the command that started it. */
final class SyncReport
{
    public const REFUSED_BY_SOURCE = 'refused_by_source';
    public const REFUSED_BY_RULES = 'refused_by_rules';

    /** How many refusals `last_error` spells out; the full list is in the log, entry by entry. */
    public const SUMMARY_CAP = 5;

    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $removed = 0;

    /** @var list<Refusal> */
    public array $refusals = [];

    public function wrote(WriteOutcome $outcome): void
    {
        match ($outcome) {
            WriteOutcome::Created => $this->created++,
            WriteOutcome::Updated => $this->updated++,
            WriteOutcome::Unchanged => $this->unchanged++,
        };
    }

    /** The source saw the entry and could not read it. */
    public function refusedBySource(string $externalId, string $language, string $reason): void
    {
        $this->refusals[] = new Refusal(self::REFUSED_BY_SOURCE, $externalId, $language, [$reason]);
    }

    /** @param list<string> $violations */
    public function refusedByRules(string $externalId, string $language, array $violations): void
    {
        $this->refusals[] = new Refusal(self::REFUSED_BY_RULES, $externalId, $language, $violations);
    }

    public function refused(): bool
    {
        return $this->refusals !== [];
    }

    /** What `last_error` holds: the first refusals, one per line, and the count of the rest, never a silent cut. */
    public function refusalSummary(): string
    {
        $lines = array_map(static fn (Refusal $r): string => $r->line(), array_slice($this->refusals, 0, self::SUMMARY_CAP));
        if (count($this->refusals) > self::SUMMARY_CAP) {
            $lines[] = 'and ' . (count($this->refusals) - self::SUMMARY_CAP) . ' more';
        }

        return implode("\n", $lines);
    }

    public function summary(): string
    {
        return sprintf(
            '%d created, %d updated, %d unchanged, %d removed, %d refused',
            $this->created,
            $this->updated,
            $this->unchanged,
            $this->removed,
            count($this->refusals),
        );
    }
}
