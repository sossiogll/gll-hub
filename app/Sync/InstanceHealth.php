<?php

declare(strict_types=1);

namespace App\Sync;

use App\Models\SourceInstance;
use App\Models\SyncStatus;
use Illuminate\Support\Carbon;

/** Writes down how a run went on the instance itself, where the admin and the scheduler read it. */
final class InstanceHealth
{
    public function started(SourceInstance $instance): void
    {
        $instance->forceFill(['last_run_at' => Carbon::now('UTC')])->save();
    }

    /**
     * The admin changed the settings or the credentials. Failing means nothing retries until they do, so it is
     * the one status that must be let go; any other keeps what the last run said until the next one replaces it.
     */
    public function reconfigured(SourceInstance $instance): void
    {
        if ($instance->status === SyncStatus::Failing) {
            $instance->forceFill(['status' => SyncStatus::Pending, 'retry_after' => null])->save();
        }
    }

    public function succeeded(SourceInstance $instance): void
    {
        $instance->forceFill([
            'status' => SyncStatus::Ok,
            'last_success_at' => Carbon::now('UTC'),
            'last_error' => null,
            'retry_after' => null,
        ])->save();
    }

    /** @param string $summary the refused entries and why, as SyncReport::refusalSummary() words them */
    public function partial(SourceInstance $instance, string $summary): void
    {
        $instance->forceFill([
            'status' => SyncStatus::Partial,
            'last_success_at' => Carbon::now('UTC'),
            'last_error' => $summary,
            'retry_after' => null,
        ])->save();
    }

    public function failed(SourceInstance $instance, Failure $failure): void
    {
        $instance->forceFill([
            'status' => $failure->status,
            'last_error' => $failure->message,
            'retry_after' => $failure->retryIn === null ? null : Carbon::now('UTC')->addSeconds($failure->retryIn),
        ])->save();
    }
}
