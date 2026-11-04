<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Source;

use Gll\Hub\Contracts\Package\Capability;
use Gll\Hub\Contracts\Package\Credential;
use Gll\Hub\Contracts\Package\Setting;

/**
 * What a source is and can do, read by the core without calling it.
 *
 * A source can be configured several times: each configuration is an INSTANCE
 * ("the feed of one author", "the feed of another"), with its own
 * values for $instanceSettings and $instanceCredentials.
 */
final readonly class SourceDefinition
{
    /**
     * @param string $key stable identifier within the package, e.g. "feed"
     * @param list<string> $produces the content type keys its entries may have
     * @param list<Capability> $capabilities each one also implemented as its interface
     * @param list<Setting> $instanceSettings
     * @param list<Credential> $instanceCredentials
     * @param int $defaultIntervalMinutes how often the core pulls when no webhook says so; 0 = only on demand
     * @param bool $listsEverything true = pull() returns every entry, so one missing from it was removed;
     *                              false = pull() returns only the latest (a feed), so the core never removes on absence
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $produces,
        public array $capabilities = [],
        public array $instanceSettings = [],
        public array $instanceCredentials = [],
        public int $defaultIntervalMinutes = 60,
        public bool $listsEverything = true,
        public string $description = '',
    ) {
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
