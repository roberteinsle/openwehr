<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Resources\StoreResource;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemCategory;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Movement;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Store;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StoreService;

/**
 * The Lager: one row per Artikel (filtered, sorted, optionally for one Lager), a click opens its page
 * (StoreTypePage). Zugang and Abgang ask for the code where the Artikel needs one and, with several
 * Lager, for the Lager; the Kleiderkammerprofil of a Kamerad offers the same two actions.
 */
class StorePage extends Page implements HasTable
{
    use InteractsWithTable, KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/lager';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Lager';

    protected static ?string $title = 'Lager';

    protected static ?int $navigationSort = 2;

    protected string $view = 'kleiderkammer::filament.pages.store';

    public function getSubheading(): ?string
    {
        return 'Alles, was in die Kleiderkammer kommt, wird hier als Zugang gebucht, was sie verlässt, als Abgang. Artikel „Einzeln erfasst“ (z. B. PSA-Jacke, -Hose, -Stiefel) kommen Stück für Stück mit ihrem Code, alle anderen (z. B. Handschuhe) als Anzahl. Ein Klick auf einen Artikel zeigt seine Teile mit Ausgabe, Rücknahme und Ausmustern.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, ?array $filters) {
                $value = fn (string $name) => $filters[$name]['value'] ?? null;
                $rows = app(StoreService::class)->byType(filled($value('store')) ? (int) $value('store') : null);
                $term = mb_strtolower(trim((string) $search));
                $category = filled($value('category')) ? ItemCategory::whereKey($value('category'))->value('name') : null;

                $rows = $rows
                    ->when($term !== '', fn ($r) => $r->filter(fn (array $row) => str_contains(mb_strtolower($row['type'].' '.$row['category']), $term)))
                    ->when(filled($value('tracked')), fn ($r) => $r->where('tracked', $value('tracked') === 'code'))
                    ->when($category !== null, fn ($r) => $r->where('category', $category))
                    ->when(filled($value('level')), fn ($r) => $r->filter(fn (array $row) => match ($value('level')) {
                        'empty' => $row['in_store'] <= 0,
                        'low' => $row['in_store'] >= 1 && $row['in_store'] <= 2,
                        default => $row['in_store'] >= 3,
                    }));

                $column = in_array($sortColumn, ['type', 'category', 'tracked', 'in_store', 'issued', 'total'], true) ? $sortColumn : 'type';
                $sorted = $rows->sortBy(fn (array $row) => is_string($row[$column]) ? mb_strtolower($row[$column]) : $row[$column], SORT_NATURAL);

                return ($sortDirection === 'desc' ? $sorted->reverse() : $sorted)->all();
            })
            ->columns([
                TextColumn::make('type')->label('Artikel')->description(fn (array $record) => $record['category'] ?: null)->searchable()->sortable()->weight('medium'),
                TextColumn::make('category')->label('Kategorie')->sortable()->placeholder('–')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracked')->label('Erfassung')->sortable()->formatStateUsing(fn ($state) => $state ? 'Einzeln mit Code' : 'Menge')->badge()->color(fn ($state) => $state ? 'info' : 'gray'),
                TextColumn::make('in_store')->label('Lager')->sortable()->alignEnd()->color(fn ($state) => $state <= 0 ? 'danger' : ($state <= 2 ? 'warning' : 'success')),
                TextColumn::make('issued')->label('Ausgegeben')->sortable()->alignEnd(),
                TextColumn::make('total')->label('Gesamt')->sortable()->alignEnd()->weight('medium'),
            ])
            ->defaultSort('type')
            ->filters([
                SelectFilter::make('tracked')->label('Erfassung')->options(['code' => 'Einzeln mit Code', 'menge' => 'Menge']),
                SelectFilter::make('category')->label('Kategorie')->options(fn () => ItemCategory::orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('store')->label('Lager')->options(fn () => Store::options())->visible(fn () => Store::several()),
                SelectFilter::make('level')->label('Bestand im Lager')->options(['empty' => 'Leer', 'low' => 'Knapp (1–2)', 'ok' => 'Ausreichend (ab 3)']),
            ])
            ->recordUrl(fn (array $record) => StoreTypePage::getUrl(['type' => $record['type_id']]))
            ->emptyStateHeading('Keine Artikel')
            ->emptyStateDescription('Lege unter „Katalog → Artikel“ die Artikel an, dann kommen sie mit „Zugang buchen“ ins Lager.')
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            self::receiveAction(),
            self::disposeAction(),
            Action::make('stores')->label('Lager verwalten')->icon('heroicon-o-building-office-2')->color('gray')->url(fn () => StoreResource::getUrl('index')),
        ];
    }

    /** The Lager field: only with several Lager; preset with the Artikel's Lager */
    public static function storeField(?ItemType $fixed): Forms\Components\Select
    {
        return Forms\Components\Select::make('store_id')->label('Lager')
            ->options(fn () => Store::options())
            ->default(fn () => $fixed && $fixed->store_id ? $fixed->store_id : Store::defaultId())
            ->required()
            ->visible(fn () => Store::several());
    }

    /** The reason of an Abgang, with suggestions */
    public static function reasonField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('reason')->label('Grund')->required()->maxLength(300)
            ->datalist(Movement::REASONS)->placeholder('z. B. verschlissen, defekt');
    }

    /** Abgang = Ausmustern from a Lager: a piece by its code or a quantity; on the Artikel page with its Artikel fixed */
    public static function disposeAction(?ItemType $fixed = null): Action
    {
        $type = fn ($get) => $fixed ?? ItemType::find($get('item_type_id'));

        return Action::make('dispose')
            ->label('Abgang buchen')
            ->icon('heroicon-o-minus')
            ->color('danger')
            ->modalHeading($fixed ? 'Abgang: '.$fixed->name : 'Abgang aus dem Lager')
            ->modalDescription('Ausmustern: Das Teil oder die Menge verlässt die Kleiderkammer endgültig (z. B. verschlissen, defekt). Was ein Kamerad hat, wird bei ihm unter „Ausrüstung“ ausgemustert.')
            ->form([
                Forms\Components\Select::make('item_type_id')->label('Artikel')->required(! $fixed)->searchable()->live()
                    ->options(fn () => ItemType::orderBy('name')->pluck('name', 'id'))
                    ->visible(! $fixed)
                    ->afterStateUpdated(function ($set, $state) {
                        $set('size_id', null);
                        $set('group_id', null);
                        $set('store_id', ItemType::whereKey($state)->value('store_id') ?? Store::defaultId());
                    }),
                Forms\Components\TextInput::make('code')->label('Code')->maxLength(50)->autofocus()
                    ->helperText('Der Code vom Etikett; ein Handscanner trägt ihn direkt ein.')
                    ->required(fn ($get) => (bool) $type($get)?->is_tracked)
                    ->visible(fn ($get) => (bool) $type($get)?->is_tracked),
                self::storeField($fixed)->visible(fn ($get) => Store::several() && $type($get) && ! $type($get)->is_tracked),
                Forms\Components\Select::make('group_id')->label('Gruppe')->live()
                    ->options(fn ($get) => ItemGroup::where('item_type_id', $type($get)?->id)->orderBy('sort_order')->pluck('name', 'id'))
                    ->visible(fn ($get) => $type($get) && ! $type($get)->is_tracked && ItemGroup::where('item_type_id', $type($get)->id)->exists()),
                Forms\Components\Select::make('size_id')->label('Größe')
                    ->options(fn ($get) => Size::where('item_type_id', $type($get)?->id)->forGroup($get('group_id'))->orderBy('sort_order')->pluck('name', 'id'))
                    ->visible(fn ($get) => $type($get) && ! $type($get)->is_tracked && $type($get)->has_size),
                Forms\Components\TextInput::make('quantity')->label('Anzahl')->numeric()->minValue(1)->default(1)
                    ->required(fn ($get) => $type($get) && ! $type($get)->is_tracked)
                    ->visible(fn ($get) => $type($get) && ! $type($get)->is_tracked),
                self::reasonField(),
            ])
            ->action(function (array $data) use ($fixed) {
                $type = $fixed ?? ItemType::findOrFail($data['item_type_id']);
                $movement = app(StoreService::class)->dispose($type, isset($data['size_id']) ? Size::find($data['size_id']) : null,
                    isset($data['group_id']) ? ItemGroup::find($data['group_id']) : null, $data['code'] ?? null, (int) ($data['quantity'] ?? 1),
                    (string) $data['reason'], auth()->user(), isset($data['store_id']) ? Store::find($data['store_id']) : null);
                Notification::make()->warning()->title($movement->quantity.' × „'.$type->name.'“ ausgemustert.')->send();
            })
            ->modalSubmitActionLabel('Ausmustern');
    }

    /**
     * Lagereingang; on the Artikel page with its Artikel fixed. From a Kleiderkammerprofil ($for) the new
     * piece or quantity can go to that Kamerad straight away.
     */
    public static function receiveAction(?ItemType $fixed = null, ?User $for = null): Action
    {
        $type = fn ($get) => $fixed ?? ItemType::find($get('item_type_id'));

        return Action::make('receive')
            ->label('Zugang buchen')
            ->icon('heroicon-o-plus')
            ->modalHeading($fixed ? 'Zugang: '.$fixed->name : 'Zugang ins Lager')
            ->form([
                Forms\Components\Select::make('item_type_id')->label('Artikel')->required(! $fixed)->searchable()->live()
                    ->options(fn () => ItemType::orderBy('name')->pluck('name', 'id'))
                    ->visible(! $fixed)
                    ->afterStateUpdated(function ($set, $state) {
                        $set('size_id', null);
                        $set('group_id', null);
                        $set('store_id', ItemType::whereKey($state)->value('store_id') ?? Store::defaultId());
                    }),
                self::storeField($fixed),
                Forms\Components\Select::make('group_id')->label('Gruppe')->live()
                    ->options(fn ($get) => ItemGroup::where('item_type_id', $type($get)?->id)->orderBy('sort_order')->pluck('name', 'id'))
                    ->visible(fn ($get) => $type($get) && ItemGroup::where('item_type_id', $type($get)->id)->exists()),
                Forms\Components\Select::make('size_id')->label('Größe')
                    ->options(fn ($get) => Size::where('item_type_id', $type($get)?->id)->forGroup($get('group_id'))->orderBy('sort_order')->pluck('name', 'id'))
                    ->visible(fn ($get) => (bool) $type($get)?->has_size),
                Forms\Components\TextInput::make('code')->label('Code')->maxLength(50)->autofocus()
                    ->helperText('Der Code vom Etikett des Teils; ein Handscanner trägt ihn direkt ein.')
                    ->required(fn ($get) => (bool) $type($get)?->is_tracked)
                    ->visible(fn ($get) => (bool) $type($get)?->is_tracked),
                Forms\Components\TextInput::make('quantity')->label('Anzahl')->numeric()->minValue(1)->default(1)
                    ->required(fn ($get) => $type($get) && ! $type($get)->is_tracked)
                    ->visible(fn ($get) => $type($get) && ! $type($get)->is_tracked),
                Forms\Components\Toggle::make('issue')->label($for ? 'Gleich an '.$for->name.' ausgeben' : '')->default(true)
                    ->visible(fn () => $for !== null),
            ])
            ->action(function (array $data) use ($fixed, $for) {
                $type = $fixed ?? ItemType::findOrFail($data['item_type_id']);
                $size = isset($data['size_id']) ? Size::find($data['size_id']) : null;
                $group = isset($data['group_id']) ? ItemGroup::find($data['group_id']) : null;
                $store = isset($data['store_id']) ? Store::find($data['store_id']) : null;
                $quantity = (int) ($data['quantity'] ?? 1);
                $result = app(StoreService::class)->receive($type, $size, $group, $data['code'] ?? null, $quantity, $store);
                $title = $type->is_tracked ? '„'.$type->name.'“ mit Code '.$result->getAttribute('code').' ist im Lager.' : $quantity.' × „'.$type->name.'“ sind im Lager.';

                if ($for && ($data['issue'] ?? false)) {
                    $issuing = app(IssuingService::class);
                    $result instanceof Item
                        ? $issuing->issueTracked($for, $result)
                        : $issuing->issueStock($for, $type, $size, $quantity, '', $group, $store ?? $result->store()->first());
                    $title .= ' Ausgegeben an '.$for->name.'.';
                }
                Notification::make()->success()->title($title)->send();
            })
            ->modalSubmitActionLabel('Buchen');
    }
}
