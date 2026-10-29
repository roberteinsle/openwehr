<?php

namespace Modules\Kleiderkammer\src\Filament\Resources;

use App\Enums\MemberStatus;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\LabelSheetGenerator;

class ItemResource extends Resource
{
    use KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/einzelteile';

    protected static ?string $model = Item::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Einzelteile';

    // No menu entry: the pieces are listed on the Artikel page of the Lager (StorePage → StoreTypePage)
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'Teil';

    protected static ?string $pluralModelLabel = 'Einzelteile';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Teil')->schema([
                Forms\Components\Select::make('item_type_id')
                    ->label('Artikeltyp')
                    ->relationship('itemType', 'name', fn ($query) => $query->where('is_tracked', true)->orderBy('name'))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->helperText(fn () => ItemType::where('is_tracked', true)->exists()
                        ? 'Nur Artikeltypen mit „Einzeln erfasst“. Massenbestand wird unter „Bestand“ gepflegt.'
                        : 'Es gibt noch keinen Artikeltyp mit „Einzeln erfasst“. Lege zuerst unter „Artikeltypen“ einen an oder aktiviere die Option dort.'),

                Forms\Components\Select::make('group_id')
                    ->live()
                    ->label('Gruppe')
                    ->options(fn ($get) => ItemGroup::where('item_type_id', $get('item_type_id'))->orderBy('sort_order')->pluck('name', 'id'))
                    ->nullable()
                    ->visible(fn ($get) => filled($get('item_type_id')) && ItemGroup::where('item_type_id', $get('item_type_id'))->exists()),

                Forms\Components\Select::make('size_id')
                    ->label('Größe')
                    ->options(fn ($get) => Size::where('item_type_id', $get('item_type_id'))->forGroup($get('group_id'))->orderBy('sort_order')->pluck('name', 'id'))
                    ->nullable()
                    ->visible(fn ($get) => filled($get('item_type_id'))),

                Forms\Components\TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(50),

                // Schwachstelle #1: status is read-only in the form (set via service actions only)
                Forms\Components\Placeholder::make('status_display')
                    ->label('Status')
                    ->content(fn ($record) => $record?->status?->label() ?? ItemStatus::Available->label()),

                Forms\Components\Textarea::make('notes')
                    ->label('Notizen')
                    ->rows(2)
                    ->nullable(),

                Forms\Components\Select::make('issue_to')
                    ->label('Ausgeben an')
                    ->options(fn () => User::where('status', MemberStatus::Active->value)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Nicht ausgeben, nur erfassen')
                    ->helperText('Optional: Das neue Teil wird direkt an dieses Mitglied ausgegeben.')
                    ->visibleOn('create'),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('itemType.name')
                    ->label('Artikeltyp')
                    ->sortable(),

                Tables\Columns\TextColumn::make('size.name')
                    ->label('Größe')
                    ->placeholder('–'),

                Tables\Columns\TextColumn::make('group.name')
                    ->label('Gruppe')
                    ->placeholder('–'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ItemStatus ? $state->label() : $state)
                    ->color(fn ($state) => match ($state) {
                        ItemStatus::Available => 'success',
                        ItemStatus::Issued => 'warning',
                        ItemStatus::Retired => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('currentIssuance.user.name')
                    ->label('Bei')
                    ->placeholder('–'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Geändert')
                    ->since()
                    ->timezone('Europe/Berlin')
                    ->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                Tables\Filters\SelectFilter::make('item_type_id')
                    ->label('Artikeltyp')
                    ->options(ItemType::where('is_tracked', true)->orderBy('name')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ItemStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),

                Tables\Filters\SelectFilter::make('size_id')
                    ->label('Größe')
                    ->relationship('size', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('group_id')
                    ->label('Gruppe')
                    ->relationship('group', 'name'),

                Tables\Filters\TrashedFilter::make(),
            ], layout: Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 5])
            ->actions([
                ...self::rowActions(),
                Actions\EditAction::make(),
                Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('printLabels')
                        ->label('Etiketten drucken')
                        ->icon('heroicon-o-printer')
                        ->action(function ($records) {
                            return app(LabelSheetGenerator::class)
                                ->download($records);
                        }),
                    Actions\DeleteBulkAction::make()->label('Archivieren'),
                ]),
            ]);
    }

    /** Ausgeben, Zurücknehmen, Ausmustern of one piece; also on the Artikel page of the Lager */
    public static function rowActions(): array
    {
        return [
            Actions\Action::make('issue')
                ->label('Ausgeben')
                ->icon('heroicon-o-arrow-right')
                ->color('primary')
                ->visible(fn (Item $record) => $record->isAvailable())
                ->form([
                    Forms\Components\Select::make('user_id')
                        ->label('Mitglied')
                        ->options(fn () => User::where('status', MemberStatus::Active->value)->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->searchable(),
                    Forms\Components\Textarea::make('notes')->label('Notizen')->nullable(),
                ])
                ->action(function (Item $record, array $data) {
                    $member = User::findOrFail($data['user_id']);
                    app(IssuingService::class)->issueTracked($member, $record, $data['notes'] ?? '');
                    Notification::make()->title('Teil ausgegeben.')->success()->send();
                }),

            Actions\Action::make('return')
                ->label('Zurücknehmen')
                ->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()
                ->visible(fn (Item $record) => $record->isIssued())
                ->action(function (Item $record) {
                    $issuedItem = $record->currentIssuance()->firstOrFail();
                    app(IssuingService::class)->return($issuedItem);
                    Notification::make()->title('Teil zurückgenommen.')->success()->send();
                }),

            Actions\Action::make('retire')
                ->label('Ausmustern')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Teil ausmustern?')
                ->visible(fn (Item $record) => ! $record->isRetired() && ! $record->isIssued())
                ->form([
                    Forms\Components\Textarea::make('notes')->label('Grund / Notiz')->nullable(),
                ])
                ->action(function (Item $record, array $data) {
                    app(IssuingService::class)->retire($record, $data['notes'] ?? '');
                    Notification::make()->title('Teil ausgemustert.')->warning()->send();
                }),

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItems::route('/'),
            'create' => Pages\CreateItem::route('/neu'),
            'edit' => Pages\EditItem::route('/{record}/bearbeiten'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }
}
