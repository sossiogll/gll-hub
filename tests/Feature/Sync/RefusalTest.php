<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\Entry;
use App\Models\SourceInstance;
use App\Models\SyncStatus;
use App\Sync\Console\SyncCommand;
use App\Sync\InstanceHealth;
use App\Sync\SourceSynchronizer;
use App\Sync\SyncReport;
use App\Sync\SyncSourceInstance;
use Gll\Hub\Contracts\Exceptions\InvalidCredentials;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\Entries;
use Tests\HubTestCase;

final class RefusalTest extends HubTestCase
{
    private function runJob(SourceInstance $instance): void
    {
        (new SyncSourceInstance($instance->id))->withFakeQueueInteractions()
            ->handle($this->app->make(SourceSynchronizer::class), $this->app->make(InstanceHealth::class), $this->app->make(LoggerInterface::class));
    }

    public function test_a_refused_entry_is_neither_written_nor_removed(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'Dune')];
        $this->sync($instance);
        $stored = Entry::sole()->getAttributes();

        $this->script->entries['catalog'] = [Entries::refused('1', 'the front matter is not valid YAML')];
        $report = $this->sync($instance);

        $this->assertSame(0, $report->removed);
        $this->assertSame(0, $report->created + $report->updated + $report->unchanged);
        $this->assertSame($stored, Entry::sole()->getAttributes());
        $this->assertSame([SyncReport::REFUSED_BY_SOURCE], array_map(fn ($r) => $r->code, $report->refusals));
    }

    public function test_a_run_with_a_refused_entry_ends_partial_and_says_why(): void
    {
        $this->travelTo('2026-05-05 10:00:00');
        $instance = $this->makeInstance(attributes: ['retry_after' => now()->addHour()]);
        $this->script->entries['catalog'] = [Entries::book('1'), Entries::refused('2', 'bad yaml', 'it')];

        $this->runJob($instance);

        $fresh = $instance->fresh();
        $this->assertSame(SyncStatus::Partial, $fresh->status);
        $this->assertSame('refused_by_source: 2 (it): bad yaml', $fresh->last_error);
        $this->assertSame('2026-05-05 10:00:00', $fresh->last_success_at->format('Y-m-d H:i:s'));
        $this->assertNull($fresh->retry_after);
        $this->assertSame(1, Entry::count());
    }

    public function test_a_rules_violation_ends_partial_with_its_own_code(): void
    {
        $instance = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1', 'No isbn', ['fields' => []])];

        $this->runJob($instance);

        $fresh = $instance->fresh();
        $this->assertSame(SyncStatus::Partial, $fresh->status);
        $this->assertStringStartsWith('refused_by_rules: 1 (en): ', $fresh->last_error);
        $this->assertStringContainsString('required field "isbn" is missing', $fresh->last_error);
    }

    public function test_a_clean_run_ends_ok_without_an_error(): void
    {
        $instance = $this->makeInstance(attributes: ['status' => SyncStatus::Partial, 'last_error' => 'old']);
        $this->script->entries['catalog'] = [Entries::book('1')];

        $this->runJob($instance);

        $this->assertSame(SyncStatus::Ok, $instance->fresh()->status);
        $this->assertNull($instance->fresh()->last_error);
    }

    public function test_the_summary_names_the_first_refusals_and_counts_the_rest(): void
    {
        $report = new SyncReport();
        for ($i = 1; $i <= SyncReport::SUMMARY_CAP + 3; $i++) {
            $report->refusedBySource("r{$i}", 'en', 'unreadable');
        }

        $lines = explode("\n", $report->refusalSummary());

        $this->assertCount(SyncReport::SUMMARY_CAP + 1, $lines);
        $this->assertSame('refused_by_source: r5 (en): unreadable', $lines[4]);
        $this->assertSame('and 3 more', $lines[5]);
        $this->assertStringContainsString('8 refused', $report->summary());
    }

    public function test_a_summary_within_the_cap_adds_nothing(): void
    {
        $report = new SyncReport();
        $report->refusedByRules('a', 'en', ['one', 'two']);

        $this->assertSame('refused_by_rules: a (en): one; two', $report->refusalSummary());
    }

    public function test_the_sync_command_exits_with_a_code_of_its_own_when_it_refused_something(): void
    {
        $partial = $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::refused('1', 'bad yaml')];

        $this->artisan('gllhub:sync', ['instance' => $partial->id])
            ->expectsOutputToContain("#{$partial->id} Library catalog: partial (refused_by_source: 1 (en): bad yaml)")
            ->assertExitCode(SyncCommand::PARTIAL);
    }

    public function test_the_sync_command_exits_cleanly_when_nothing_was_refused(): void
    {
        $this->makeInstance();
        $this->script->entries['catalog'] = [Entries::book('1')];

        $this->artisan('gllhub:sync', ['--all' => true])->assertExitCode(0);
    }

    public function test_a_failure_wins_over_a_partial_in_the_exit_code(): void
    {
        $this->makeInstance();
        $this->makeInstance('latest');
        $this->script->failures['catalog'] = new InvalidCredentials('the token was refused');
        $this->script->entries['latest'] = [Entries::refused('1', 'bad yaml')];

        $this->artisan('gllhub:sync', ['--all' => true])->assertExitCode(1);
    }
}
