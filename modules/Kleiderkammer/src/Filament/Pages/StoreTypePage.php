<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use App\Support\Money;
use Filament\Actions;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Movement;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Models\Store;
use Modules\Kleiderkammer\src\Services\LabelSheetGenerator;
use Modules\Kleiderkammer\src\Services\StoreService;

/**
 * One Artikel of the Lager: the stock by group and size, and below either its pieces with code
 * (Ausgeben, Zurücknehmen, Ausmustern, Bearbeiten, Etiketten) or its quantities (Ausgeben, Korrigieren).
 */
class StoreTypePage extends Page implements HasTable
{
    use InteractsWithTable, KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/lager/{type}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'kleiderkammer::filament.pages.store-type';

    #[Locked]
    public ItemType $itemType;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'kleiderkammer-lager-artikel';
    }

    public function mount(int|string $type): void
    {
        $this->itemType = ItemType::with('category')->findOrFail($type);
    }

    public function getTitle(): string
    {
        return $this->itemType->name;
    }

    public function getSubheading(): ?string
    {
        $type = $this->itemType;

        return collect([
            $type->category?->name,
            $type->is_tracked ? 'Einzeln mit Code' : 'Menge ohne Code',
            $type->price_net_cents === null ? 'kein Preis hinterlegt' : 'Preis '.Money::format($type->price_net_cents).' netto / '.Money::format((int) $type->priceGrossCents()).' brutto',
        ])->filter()->implode(' · ');
    }

    public function getBreadcrumbs(): array
    {
        return [StorePage::getUrl() => 'Lager', $this->itemType->name];
    }

    protected function getViewData(): array
    {
        return [
            'levels' => app(StoreService::class)->levels()->where('type_id', $this->itemType->id)->values(),
            'movements' => Movement::where('item_type_id', $this->itemType->id)->with('store')->latest('created_at')->latest('id')->limit(20)->get(),
            'several' => Store::several(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            StorePage::receiveAction($this->itemType),
            StorePage::disposeAction($this->itemType),
            Actions\Action::make('editType')->label('Artikel bearbeiten')->icon('heroicon-o-pencil-square')->color('gray')
                ->url(ItemTypeResource::getUrl('edit', ['record' => $this->itemType])),
        ];
    }

    public function table(Table $table): Table
    {
        return $this->itemType->is_tracked ? $this->itemsTable($table) : $this->stockTable($table);
    }

    private function itemsTable(Table $table): Table
    {
        return $table
            ->query(Item::query()->where('item_type_id', $this->itemType->id)->with(['size', 'group', 'store', 'currentIssuance.user']))
            ->heading('Teile')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Code')->searchable()->sortable()->copyable(),
                Tables\Columns\TextColumn::make('group.name')->label('Gruppe')->placeholder('–'),
                Tables\Columns\TextColumn::make('size.name')->label('Größe')->placeholder('–'),
                Tables\Columns\TextColumn::make('store.name')->label('Lager')->visible(fn () => Store::several()),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ItemStatus ? $state->label() : $state)
                    ->color(fn ($state) => match ($state) {
                        ItemStatus::Available => 'success',
                        ItemStatus::Issued => 'warning',
                        ItemStatus::Retired => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('currentIssuance.user.name')->label('Bei')->placeholder('–'),
                Tables\Columns\TextColumn::make('updated_at')->label('Geändert')->since()->timezone('Europe/Berlin')->sortable(),
            ])
            ->defaultSort('code')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')
                    ->options(collect(ItemStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->recordUrl(fn (Item $record) => ItemResource::getUrl('edit', ['record' => $record]))
            ->actions([
                ...ItemResource::rowActions(),
                Actions\Action::make('edit')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->color('gray')
                    ->url(fn (Item $record) => ItemResource::getUrl('edit', ['record' => $record])),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('printLabels')->label('Etiketten drucken')->icon('heroicon-o-printer')
                        ->action(fn ($records) => app(LabelSheetGenerator::class)->download($records)),
                ]),
            ])
            ->emptyStateHeading('Noch keine Teile')
            ->emptyStateDescription('Mit „Zugang buchen“ kommt jedes Teil mit seinem Code ins Lager.');
    }

    private function stockTable(Table $table): Table
    {
        return $table
            ->query(Stock::query()->where('item_type_id', $this->itemType->id)->with(['size', 'group', 'store']))
            ->heading('Mengen im Lager')
            ->columns([
                Tables\Columns\TextColumn::make('group.name')->label('Gruppe')->placeholder('–'),
                Tables\Columns\TextColumn::make('size.name')->label('Größe')->placeholder('–'),
                Tables\Columns\TextColumn::make('store.name')->label('Lager')->visible(fn () => Store::several()),
                Tables\Columns\TextColumn::make('quantity')->label('Im Lager')->alignEnd()->sortable()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : ($state <= 2 ? 'warning' : 'success')),
            ])
            ->actions([
                ...StockResource::rowActions(),
                Actions\Action::make('edit')->label('Korrigieren')->icon('heroicon-o-pencil-square')->color('gray')
                    ->url(fn (Stock $record) => StockResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('Nichts im Lager')
            ->emptyStateDescription('Mit „Zugang buchen“ kommt eine Anzahl ins Lager.');
    }
}
