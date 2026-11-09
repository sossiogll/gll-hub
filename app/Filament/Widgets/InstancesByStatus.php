<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\SourceInstance;
use App\Models\SyncStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InstancesByStatus extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Source instances';

    protected function getStats(): array
    {
        $counts = SourceInstance::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return array_map(
            static fn (SyncStatus $status): Stat => Stat::make(ucfirst($status->value), (int) ($counts[$status->value] ?? 0))
                ->color(match ($status) {
                    SyncStatus::Ok => 'success',
                    SyncStatus::Pending => 'gray',
                    SyncStatus::Retrying, SyncStatus::Partial => 'warning',
                    SyncStatus::Failing, SyncStatus::Failed => 'danger',
                }),
            SyncStatus::cases(),
        );
    }
}
