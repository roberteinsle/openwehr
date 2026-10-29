<?php

namespace Modules\Kleiderkammer\src\Filament\Resources;

use App\Support\Money;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
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
use Modules\Kleiderkammer\src\Models\Store;

class ItemTypeResource extends Resource
{
    use KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/artikeltypen';

    protected static ?string $model = ItemType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Artikel';

    protected static ?string $modelLabel = 'Artikel';

    protected static ?string $pluralModelLabel = 'Artikel';

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?int $navigationSort = 21;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Stammdaten')->schema([
                Forms\Components\Select::make('category_id')
                    ->label('Kategorie')
                    ->options(fn () => ItemCategory::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->nullable(),

                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),

                // With several Lager: where this Artikel is kept (preset for Zugang, Abgang and Rücknahme)
                Forms\Components\Select::make('store_id')
                    ->label('Lager')
                    ->options(fn () => Store::options())
                    ->placeholder('Erstes Lager')
                    ->visible(fn () => Store::several()),

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
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'store']))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable()->weight('medium'),
                Tables\Columns\TextColumn::make('category.name')->label('Kategorie')->sortable()->placeholder('–')->toggleable(),
                Tables\Columns\TextColumn::make('store.name')->label('Lager')->sortable()->placeholder('Standard')->toggleable()->visible(fn () => Store::several()),
                Tables\Columns\TextColumn::make('code_prefix')->label('Präfix')->sortable()->placeholder('–')->toggleable(),
                Tables\Columns\TextColumn::make('price_net_cents')->label('Netto')->sortable()->toggleable()
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))->placeholder('–')->alignEnd(),
                Tables\Columns\TextColumn::make('price_gross')->label('Brutto')->toggleable()
                    ->state(fn (ItemType $record) => $record->priceGrossCents())
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('price_net_cents', $direction))
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))->placeholder('–')->alignEnd(),
                Tables\Columns\IconColumn::make('is_tracked')->label('Einzeln')->boolean()->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('has_size')->label('Größe')->boolean()->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('is_washable')->label('Wäsche')->boolean()->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('is_chainsaw_protection')->label('Kettensäge')->boolean()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('sort_order')->label('Reihenf.')->sortable()->toggleable(isToggledHiddenByDefault: true),
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
                Actions\Action::make('copy')
                    ->label('Kopieren')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->modalHeading(fn (ItemType $record) => '„'.$record->name.'“ kopieren')
                    ->modalDescription('Übernommen werden Kategorie, Preis, Eigenschaften, Gruppen und Größen – keine Teile und kein Bestand. Den Code-Präfix bitte neu vergeben.')
                    ->fillForm(fn (ItemType $record) => ['name' => $record->name.' (Kopie)'])
                    ->form([Forms\Components\TextInput::make('name')->label('Name der Kopie')->required()->maxLength(255)])
                    ->modalSubmitActionLabel('Kopieren')
                    ->action(function (ItemType $record, array $data) {
                        $copy = $record->copyAs(trim($data['name']));
                        Notification::make()->title('„'.$copy->name.'“ angelegt.')->success()->send();

                        return redirect(self::getUrl('edit', ['record' => $copy]));
                    }),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('copySelected')
                        ->label('Kopieren')
                        ->icon('heroicon-o-document-duplicate')
                        ->requiresConfirmation()
                        ->modalHeading('Markierte Artikel kopieren?')
                        ->modalDescription('Jeder bekommt eine Kopie „… (Kopie)“ mit Kategorie, Preis, Eigenschaften, Gruppen und Größen, ohne Code-Präfix.')
                        ->action(function ($records) {
                            $records->each(fn (ItemType $type) => $type->copyAs($type->name.' (Kopie)'));
                            Notification::make()->title($records->count().' Artikel kopiert.')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Actions\BulkAction::make('deleteSelected')
                        ->label('Löschen')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Markierte Artikel löschen?')
                        ->modalDescription('Artikel mit Teilen, Bestand oder Ausgaben bleiben erhalten.')
                        ->action(function ($records) {
                            [$deleted, $kept] = $records->partition(fn (ItemType $type) => $type->deleteIfUnused());
                            $note = Notification::make()->title($deleted->count().' Artikel gelöscht.');
                            $kept->isEmpty() ? $note->success() : $note->warning()->body('Noch verwendet und deshalb nicht gelöscht: '.$kept->pluck('name')->implode(', ').'.');
                            $note->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
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
