<?php

declare(strict_types=1);

namespace App\Sync\Console;

use App\Models\SourceInstance;
use App\Models\SyncStatus;
use App\Sync\SyncSourceInstance;
use Illuminate\Console\Command;

final class SyncCommand extends Command
{
    /** Finished, but refused some entries: not a success and not a failure. */
    public const PARTIAL = 3;

    protected $signature = 'gllhub:sync {instance? : The id of a source instance} {--all : Every enabled instance}';

    protected $description = 'Sync a source instance now, waiting for the result';

    public function handle(): int
    {
        $id = $this->argument('instance');

        if ($this->option('all')) {
            $instances = SourceInstance::enabled()->get();
        } elseif ($id !== null) {
            $instances = SourceInstance::whereKey($id)->get();
            if ($instances->isEmpty()) {
                $this->error("There is no source instance {$id}.");

                return self::FAILURE;
            }
        } else {
            $this->error('Name an instance, or use --all.');

            return self::FAILURE;
        }

        $failed = false;
        $partial = false;
        foreach ($instances as $instance) {
            SyncSourceInstance::dispatchSync($instance->id);
            $instance->refresh();

            $line = "#{$instance->id} {$instance->label}: {$instance->status->value}";
            $detail = $instance->last_error === null ? '' : " ({$instance->last_error})";
            switch ($instance->status) {
                case SyncStatus::Ok:
                    $this->info($line);
                    break;
                case SyncStatus::Partial:
                    $partial = true;
                    $this->warn($line . $detail);
                    break;
                default:
                    $failed = true;
                    $this->error($line . $detail);
            }
        }

        return match (true) {
            $failed => self::FAILURE,
            $partial => self::PARTIAL,
            default => self::SUCCESS,
        };
    }
}
