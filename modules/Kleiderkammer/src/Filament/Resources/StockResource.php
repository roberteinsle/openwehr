<?php

namespace Modules\Kleiderkammer\src\Filament\Resources;

use App\Enums\MemberStatus;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Pages\StorePage;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StoreService;

class StockResource extends Resource
{
    use KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/massenbestand';

    protected static ?string $model = Stock::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Massenbestand';

    // No menu entry: the quantities are listed on the Artikel page of the Lager (StorePage → StoreTypePage)
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'Bestandsposition';

    protected static ?string $pluralModelLabel = 'Massenbestand';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Select::make('item_type_id')
                ->label('Artikeltyp')
                ->options(ItemType::where('is_tracked', false)->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->searchable()
                ->reactive(),

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

            Forms\Components\TextInput::make('quantity')
                ->label('Bestand')
                ->numeric()
                ->required()
                ->minValue(0)
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('itemType.name')
                    ->label('Artikeltyp')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('size.name')
                    ->label('Größe')
                    ->placeholder('–'),

                Tables\Columns\TextColumn::make('group.name')
                    ->label('Gruppe')
                    ->placeholder('–'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Bestand')
                    ->sortable()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : ($state <= 2 ? 'warning' : 'success')),
            ])
            ->defaultSort('itemType.name')
            ->filters([
                Tables\Filters\SelectFilter::make('item_type_id')
                    ->label('Artikeltyp')
                    ->options(ItemType::where('is_tracked', false)->orderBy('name')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('size_id')
                    ->label('Größe')
                    ->relationship('size', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('group_id')
                    ->label('Gruppe')
                    ->relationship('group', 'name'),

                Tables\Filters\SelectFilter::make('level')
                    ->label('Bestand')
                    ->options(['empty' => 'Leer', 'low' => 'Knapp (1–2)', 'ok' => 'Ausreichend (ab 3)'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'empty' => $query->where('quantity', '<=', 0),
                        'low' => $query->whereBetween('quantity', [1, 2]),
                        'ok' => $query->where('quantity', '>=', 3),
                        default => $query,
                    }),
            ], layout: Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->actions([
                ...self::rowActions(),
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    /** Ausgeben from one stock position; also on the Artikel page of the Lager */
    public static function rowActions(): array
    {
        return [
            Actions\Action::make('issue')
                ->label('Ausgeben')
                ->icon('heroicon-o-arrow-right')
                ->color('primary')
                ->visible(fn (Stock $record) => $record->quantity > 0)
                ->form([
                    Forms\Components\Select::make('user_id')
                        ->label('Mitglied')
                        ->options(fn () => User::where('status', MemberStatus::Active->value)->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->searchable(),
                    Forms\Components\TextInput::make('quantity')
                        ->label('Anzahl')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(1),
                    Forms\Components\Textarea::make('notes')->label('Notizen')->nullable(),
                ])
                ->action(function (Stock $record, array $data) {
                    $member = User::findOrFail($data['user_id']);
                    app(IssuingService::class)->issueStock(
                        $member,
                        $record->itemType,
                        $record->size,
                        (int) $data['quantity'],
                        $data['notes'] ?? '',
                        $record->group,
                    );
                    Notification::make()->title('Bestand ausgegeben.')->success()->send();
                }),

            Actions\Action::make('dispose')
                ->label('Ausmustern')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (Stock $record) => $record->quantity > 0)
                ->modalHeading('Aus dem Lager ausmustern')
                ->modalSubmitActionLabel('Ausmustern')
                ->form([
                    Forms\Components\TextInput::make('quantity')->label('Anzahl')->numeric()->required()->minValue(1)->default(1),
                    StorePage::reasonField(),
                ])
                ->action(function (Stock $record, array $data) {
                    app(StoreService::class)->dispose($record->itemType, $record->size, $record->group, null, (int) $data['quantity'], (string) $data['reason'], auth()->user());
                    Notification::make()->title((int) $data['quantity'].' ausgemustert.')->warning()->send();
                }),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStock::route('/'),
            'create' => Pages\CreateStock::route('/neu'),
            'edit' => Pages\EditStock::route('/{record}/bearbeiten'),
        ];
    }
}
