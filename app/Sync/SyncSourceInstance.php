<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\SourceInstance;
use App\Models\SyncStatus;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs one source instance, or only some of its entries when a webhook named them.
 *
 * Unique until it starts: the scheduler and webhooks can ask for the same sync many
 * times while the queue is busy and only one runs. Two different syncs of the same
 * instance never run at once, since both write its cursor and its entries.
 */
final class SyncSourceInstance implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A lock whose worker died would otherwise block the sync forever. */
    public int $uniqueFor = 3600;

    /** @param list<string>|null $externalIds */
    public function __construct(
        public readonly int $instanceId,
        public readonly ?array $externalIds = null,
    ) {
    }

    public function uniqueId(): string
    {
        $ids = $this->externalIds;
        if ($ids !== null) {
            sort($ids);
        }

        return "sync-instance-{$this->instanceId}-" . ($ids === null ? 'all' : sha1(implode("\0", $ids)));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("sync-instance-{$this->instanceId}"))->releaseAfter(30)->expireAfter(900)];
    }

    /** Releases (retries, waiting) are not failures: the job lives until this moment. */
    public function retryUntil(): DateTimeInterface
    {
        return Carbon::now()->addHours(2);
    }

    public function handle(SourceSynchronizer $synchronizer, InstanceHealth $health, LoggerInterface $logger): void
    {
        $instance = SourceInstance::enabled()->find($this->instanceId);
        if ($instance === null) {
            return;
        }

        $health->started($instance);

        // A partial instance holds refusals that only a run over everything can confirm or clear.
        $externalIds = $instance->status === SyncStatus::Partial ? null : $this->externalIds;

        try {
            $report = $synchronizer->run($instance, $externalIds);
        } catch (Throwable $e) {
            $failure = Failure::of($e, $this->attempts());
            $health->failed($instance, $failure);

            if ($failure->unexpected) {
                report($e);
            }
            if ($failure->retryIn !== null) {
                $this->release($failure->retryIn);
            }

            return;
        }

        if ($report->refused()) {
            $health->partial($instance, $report->refusalSummary());
        } else {
            $health->succeeded($instance);
        }
        $logger->info('Sync finished', ['instance' => $instance->id, 'package' => $instance->package, 'report' => $report->summary()]);
    }

    /** Called by the queue when it gives up (retry window over, worker killed): the status must not stay "retrying". */
    public function failed(Throwable $e): void
    {
        $instance = SourceInstance::find($this->instanceId);
        if ($instance !== null) {
            app(InstanceHealth::class)->failed($instance, new Failure(SyncStatus::Failed, 'The sync did not finish: ' . $e->getMessage()));
        }
    }
}
