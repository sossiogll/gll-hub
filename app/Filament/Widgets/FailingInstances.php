<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\SourceInstances\SourceInstanceResource;
use App\Models\SourceInstance;
use App\Models\SyncStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class FailingInstances extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Needing attention')
            ->query(SourceInstance::query()->whereIn('status', [SyncStatus::Partial->value, SyncStatus::Retrying->value, SyncStatus::Failing->value, SyncStatus::Failed->value])->orderBy('label'))
            ->columns([
                TextColumn::make('label')->url(static fn (SourceInstance $record): string => SourceInstanceResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('status')->badge()->formatStateUsing(static fn (SyncStatus $state): string => ucfirst($state->value))->color(static fn (SyncStatus $state): string => $state === SyncStatus::Partial ? 'warning' : 'danger'),
                TextColumn::make('last_error')->limit(80)->wrap(),
                TextColumn::make('last_run_at')->label('Last run')->dateTime(),
            ])
            ->emptyStateHeading('Nothing is failing')
            ->paginated(false);
    }
}
