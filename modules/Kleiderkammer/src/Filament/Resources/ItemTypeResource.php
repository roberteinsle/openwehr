<?php

namespace Modules\Kleiderkammer\src\Filament\Resources;

use App\Support\Money;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\RelationManagers;
use Modules\Kleiderkammer\src\Models\ItemCategory;
use Modules\Kleiderkammer\src\Models\ItemType;

class ItemTypeResource extends Resource
{
    use KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/artikeltypen';

    protected static ?string $model = ItemType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Artikeltypen';

    protected static ?string $modelLabel = 'Artikeltyp';

    protected static ?string $pluralModelLabel = 'Artikeltypen';

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?int $navigationSort = 21;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Stammdaten')->schema([
                Forms\Components\Select::make('category_id')
                    ->label('Kategorie')
                    ->options(ItemCategory::orderBy('sort_order')->pluck('name', 'id'))
                    ->searchable()
                    ->nullable(),

                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('code_prefix')
                    ->label('Code-Präfix')
                    ->maxLength(10)
                    ->helperText('Kürzel für den Artikelcode, z. B. „EJA" für Einsatzjacke'),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Reihenfolge')
                    ->numeric()
                    ->default(0),

                // Net is typed, gross follows (19 % USt.); both appear in the Inventar
                Forms\Components\TextInput::make('price_net_cents')
                    ->label('Preis netto (€ je Stück)')
                    ->placeholder('z. B. 129,90')
                    ->formatStateUsing(fn ($state) => Money::input($state === null ? null : (int) $state))
                    ->dehydrateStateUsing(fn ($state) => Money::parse($state))
                    ->rule(fn () => fn (string $attribute, $value, \Closure $fail) => filled($value) && Money::parse($value) === null ? $fail('Bitte einen Betrag wie 129,90 eingeben.') : null)
                    ->live(onBlur: true),

                Forms\Components\Placeholder::make('price_gross')
                    ->label('Preis brutto (berechnet, '.ItemType::VAT_PERCENT.' % USt.)')
                    ->content(fn ($get) => ($cents = ItemType::gross(Money::parse($get('price_net_cents')))) === null ? '–' : Money::format($cents)),
            ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),

            Section::make('Eigenschaften')->schema([
                Forms\Components\Toggle::make('is_tracked')
                    ->label('Einzeln erfasst')
                    ->default(true)
                    ->helperText('Eigener Code pro Teil. Aus für Massenbestand wie Handschuhe oder Hemden.'),

                Forms\Components\Toggle::make('has_size')
                    ->label('Größe relevant')
                    ->default(true)
                    ->helperText('Teile und Bestand werden je Größe geführt.'),

                Forms\Components\Toggle::make('is_washable')
                    ->label('Wäscheprotokoll')
                    ->default(false)
                    ->helperText('Wäsche und Imprägnierung werden je Teil protokolliert.'),

                Forms\Components\Toggle::make('is_chainsaw_protection')
                    ->label('Kettensägenschutz')
                    ->default(false)
                    ->helperText('Schnittschutzausrüstung für Kettensägenarbeiten.'),
            ])
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Kategorie')
                    ->sortable()
                    ->placeholder('–'),

                Tables\Columns\TextColumn::make('code_prefix')
                    ->label('Präfix')
                    ->placeholder('–'),

                Tables\Columns\TextColumn::make('price_net_cents')
                    ->label('Netto')
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->placeholder('–')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('price_gross')
                    ->label('Brutto')
                    ->state(fn (ItemType $record) => $record->priceGrossCents())
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->placeholder('–')
                    ->alignEnd(),

                Tables\Columns\IconColumn::make('is_tracked')
                    ->label('Einzeln')
                    ->boolean(),

                Tables\Columns\IconColumn::make('has_size')
                    ->label('Größe')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_washable')
                    ->label('Wäsche')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_chainsaw_protection')
                    ->label('Kettensäge')
                    ->boolean(),

                Tables\Columns\TextColumn::make('groups.name')
                    ->label('Gruppen')
                    ->badge()
                    ->placeholder('–'),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Reihenf.')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Kategorie')
                    ->relationship('category', 'name', fn ($query) => $query->orderBy('sort_order')),

                Tables\Filters\TernaryFilter::make('has_size')
                    ->label('Größe relevant'),

                Tables\Filters\TernaryFilter::make('is_washable')
                    ->label('Wäscheprotokoll'),

                Tables\Filters\TernaryFilter::make('is_tracked')
                    ->label('Einzeln erfasst'),

                Tables\Filters\TernaryFilter::make('is_chainsaw_protection')
                    ->label('Kettensägenschutz'),
            ], layout: Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 5])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action
                ->button()
                ->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SizesRelationManager::class,
            RelationManagers\GroupsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItemTypes::route('/'),
            'create' => Pages\CreateItemType::route('/neu'),
            'edit' => Pages\EditItemType::route('/{record}/bearbeiten'),
        ];
    }
}
