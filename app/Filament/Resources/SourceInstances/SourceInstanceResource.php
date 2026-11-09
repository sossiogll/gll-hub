<?php

declare(strict_types=1);

namespace App\Filament\Resources\SourceInstances;

use App\Filament\Fields\FieldFactory;
use App\Filament\Resources\Entries\EntryResource;
use App\Filament\Resources\SourceInstances\Pages\CreateSourceInstance;
use App\Filament\Resources\SourceInstances\Pages\EditSourceInstance;
use App\Filament\Resources\SourceInstances\Pages\ListSourceInstances;
use App\Models\SourceInstance;
use App\Models\SyncStatus;
use App\Packages\Configuration;
use App\Packages\PackageRegistry;
use App\Sync\SyncSourceInstance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gll\Hub\Contracts\Source\SourceDefinition;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SourceInstanceResource extends Resource
{
    protected static ?string $model = SourceInstance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Sources';

    protected static ?string $navigationLabel = 'Source instances';

    protected static ?string $modelLabel = 'source instance';

    protected static ?string $recordTitleAttribute = 'label';

    /** The source a form value ("package/key", the registry's own reference) stands for. */
    public static function definition(?string $reference): ?SourceDefinition
    {
        $definition = $reference === null ? null : (app(PackageRegistry::class)->sources()[$reference] ?? null)?->definition;

        return $definition instanceof SourceDefinition ? $definition : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('source')
                ->options(static fn (): array => self::sourceOptions())
                ->required()
                ->live()
                // What an instance pulls from cannot change: its entries and cursor belong to that source.
                ->disabled(static fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(static fn (string $operation): bool => $operation === 'create')
                ->afterStateUpdated(static function (Set $set, Get $get, ?string $state): void {
                    $definition = self::definition($state);
                    // A default is saved with the instance: the source's context only returns what was saved.
                    $set('settings', Configuration::settings($definition?->instanceSettings ?? [], []));
                    $set('credentials', []);
                    if (blank($get('label')) && $definition !== null) {
                        $set('label', $definition->label);
                    }
                }),
            TextInput::make('label')->required()->maxLength(255),
            Toggle::make('enabled')->default(true),
            TextInput::make('interval_minutes')
                ->label('Interval (minutes)')
                ->type('number')
                ->rules(['nullable', 'integer', 'min:0'])
                ->placeholder(static fn (Get $get): ?string => ($definition = self::definition($get('source'))) === null ? null : "Default: {$definition->defaultIntervalMinutes}")
                ->helperText('Empty = the source default. 0 = only when you sync it by hand.'),
            Section::make('Settings')
                ->columns(2)
                ->schema(static fn (Get $get): array => FieldFactory::settings(self::definition($get('source'))?->instanceSettings ?? []))
                ->visible(static fn (Get $get): bool => (self::definition($get('source'))?->instanceSettings ?? []) !== []),
            Section::make('Credentials')
                ->description('Stored encrypted and never shown again. Leave a field blank to keep what is stored.')
                ->columns(2)
                ->schema(static fn (Get $get, ?Model $record): array => FieldFactory::credentials(
                    self::definition($get('source'))?->instanceCredentials ?? [],
                    array_keys($record?->credentials ?? []),
                ))
                ->visible(static fn (Get $get): bool => (self::definition($get('source'))?->instanceCredentials ?? []) !== []),
        ]);
    }

    public static function table(Table $table): Table
    {
        $registry = static fn (): PackageRegistry => app(PackageRegistry::class);

        return $table
            ->columns([
                TextColumn::make('label')->searchable()->sortable(),
                TextColumn::make('source_key')
                    ->label('Source')
                    ->formatStateUsing(static fn (string $state, SourceInstance $record): string => $registry()->source($record->package, $state)?->definition->label ?? $state)
                    ->description(static fn (SourceInstance $record): string => $registry()->package($record->package)?->manifest->label ?? $record->package),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (SyncStatus $state): string => ucfirst($state->value))
                    ->color(static fn (SyncStatus $state): string => match ($state) {
                        SyncStatus::Ok => 'success',
                        SyncStatus::Pending => 'gray',
                        SyncStatus::Retrying, SyncStatus::Partial => 'warning',
                        SyncStatus::Failing, SyncStatus::Failed => 'danger',
                    }),
                TextColumn::make('last_run_at')->label('Last run')->dateTime()->placeholder('Never')->sortable(),
                TextColumn::make('last_success_at')->label('Last success')->dateTime()->placeholder('Never')->sortable(),
                TextColumn::make('last_error')
                    ->label('Last error')
                    ->limit(60)
                    ->tooltip(static fn (TextColumn $column): ?string => strlen((string) $column->getState()) > 60 ? $column->getState() : null),
                TextColumn::make('entries_count')->label('Entries')->counts('entries')->sortable(),
                IconColumn::make('enabled')->boolean(),
            ])
            ->defaultSort('label')
            ->recordActions([
                Action::make('sync')
                    ->label('Sync now')
                    ->icon(Heroicon::ArrowPath)
                    ->visible(static fn (SourceInstance $record): bool => $record->enabled)
                    ->action(static function (SourceInstance $record): void {
                        SyncSourceInstance::dispatch($record->id);
                        Notification::make()->success()->title('Sync queued')->body("{$record->label} will sync as soon as the queue picks it up.")->send();
                    }),
                Action::make('entries')
                    ->icon(Heroicon::DocumentText)
                    ->url(static fn (SourceInstance $record): string => EntryResource::getUrl('index', ['filters' => ['origin' => ['value' => $record->id]]])),
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('toggle')
                        ->label(static fn (SourceInstance $record): string => $record->enabled ? 'Disable' : 'Enable')
                        ->icon(static fn (SourceInstance $record) => $record->enabled ? Heroicon::Pause : Heroicon::Play)
                        ->action(static fn (SourceInstance $record) => $record->update(['enabled' => ! $record->enabled])),
                    DeleteAction::make()->modalDescription('Its entries stay, as hand-written ones.'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSourceInstances::route('/'),
            'create' => CreateSourceInstance::route('/create'),
            'edit' => EditSourceInstance::route('/{record}/edit'),
        ];
    }

    /** @return array<string, array<string, string>> the registry's sources, grouped under their package */
    private static function sourceOptions(): array
    {
        $registry = app(PackageRegistry::class);
        $options = [];
        foreach ($registry->sources() as $reference => $source) {
            $package = $registry->package($source->package)?->manifest->label ?? $source->package;
            $options[$package][$reference] = $source->definition->label;
        }

        return $options;
    }
}
