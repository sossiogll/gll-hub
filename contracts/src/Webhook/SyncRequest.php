<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Webhook;

/**
 * What a webhook asks the core to sync again. The core finds the instances of
 * $source whose settings match $instance and queues the work; it decides when.
 */
final readonly class SyncRequest
{
    /**
     * @param string $source a source key listed in WebhookDefinition::$refreshes
     * @param array<string, scalar> $instance setting values that identify the instance(s), e.g. ["feed" => "https://example.test/feed"]
     * @param list<string> $externalIds only these entries (needs Capability::SingleFetch); empty = the whole instance
     */
    public function __construct(
        public string $source,
        public array $instance = [],
        public array $externalIds = [],
    ) {
    }
}
