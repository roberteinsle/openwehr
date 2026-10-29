<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Services\StoreService;

/** The Lager: Einzelteile and Massenbestand in one list, and the Lagereingang (with code where the Artikeltyp needs one) */
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
        return 'Alles, was in die Kleiderkammer kommt, wird hier als Zugang gebucht. Artikeltypen „Einzeln erfasst“ (z. B. PSA-Jacke, -Hose, -Stiefel) kommen Stück für Stück mit ihrem Code, alle anderen (z. B. Handschuhe) als Anzahl. Ausgegeben wird aus dem Lager im Kleiderkammerprofil des Kameraden.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search) {
                $rows = app(StoreService::class)->levels();
                $term = mb_strtolower(trim((string) $search));

                return $term === '' ? $rows->all() : $rows->filter(fn (array $r) => str_contains(mb_strtolower($r['type'].' '.$r['category'].' '.$r['group'].' '.$r['size']), $term))->all();
            })
            ->columns([
                TextColumn::make('type')->label('Artikeltyp')->description(fn (array $record) => $record['category'] ?: null)->searchable(),
                TextColumn::make('group')->label('Gruppe')->placeholder('–'),
                TextColumn::make('size')->label('Größe')->placeholder('–'),
                TextColumn::make('tracked')->label('Erfassung')->formatStateUsing(fn ($state) => $state ? 'Einzeln mit Code' : 'Menge')->badge()->color(fn ($state) => $state ? 'info' : 'gray'),
                TextColumn::make('in_store')->label('Im Lager')->alignEnd()->color(fn ($state) => $state <= 0 ? 'danger' : ($state <= 2 ? 'warning' : 'success')),
                TextColumn::make('issued')->label('Ausgegeben')->alignEnd(),
            ])
            ->emptyStateHeading('Das Lager ist leer')
            ->emptyStateDescription('Mit „Zugang buchen“ kommen die ersten Teile ins Lager.')
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receive')
                ->label('Zugang buchen')
                ->icon('heroicon-o-plus')
                ->modalHeading('Zugang ins Lager')
                ->form([
                    Forms\Components\Select::make('item_type_id')->label('Artikeltyp')->required()->searchable()->live()
                        ->options(fn () => ItemType::orderBy('name')->pluck('name', 'id'))
                        ->afterStateUpdated(function ($set) {
                            $set('size_id', null);
                            $set('group_id', null);
                        }),
                    Forms\Components\Select::make('group_id')->label('Gruppe')->live()
                        ->options(fn ($get) => ItemGroup::where('item_type_id', $get('item_type_id'))->orderBy('sort_order')->pluck('name', 'id'))
                        ->visible(fn ($get) => filled($get('item_type_id')) && ItemGroup::where('item_type_id', $get('item_type_id'))->exists()),
                    Forms\Components\Select::make('size_id')->label('Größe')
                        ->options(fn ($get) => Size::where('item_type_id', $get('item_type_id'))->forGroup($get('group_id'))->orderBy('sort_order')->pluck('name', 'id'))
                        ->visible(fn ($get) => filled($get('item_type_id')) && ItemType::find($get('item_type_id'))?->has_size),
                    Forms\Components\TextInput::make('code')->label('Code')->maxLength(50)->autofocus()
                        ->helperText('Der Code vom Etikett des Teils; ein Handscanner trägt ihn direkt ein.')
                        ->required(fn ($get) => (bool) ItemType::find($get('item_type_id'))?->is_tracked)
                        ->visible(fn ($get) => (bool) ItemType::find($get('item_type_id'))?->is_tracked),
                    Forms\Components\TextInput::make('quantity')->label('Anzahl')->numeric()->minValue(1)->default(1)
                        ->required(fn ($get) => filled($get('item_type_id')) && ! ItemType::find($get('item_type_id'))?->is_tracked)
                        ->visible(fn ($get) => filled($get('item_type_id')) && ! ItemType::find($get('item_type_id'))?->is_tracked),
                ])
                ->action(function (array $data, Action $action) {
                    $type = ItemType::findOrFail($data['item_type_id']);
                    $result = app(StoreService::class)->receive($type, isset($data['size_id']) ? Size::find($data['size_id']) : null,
                        isset($data['group_id']) ? ItemGroup::find($data['group_id']) : null, $data['code'] ?? null, (int) ($data['quantity'] ?? 1));
                    Notification::make()->success()
                        ->title($type->is_tracked ? '„'.$type->name.'“ mit Code '.$result->code.' ist im Lager.' : (int) $data['quantity'].' × „'.$type->name.'“ sind im Lager.')
                        ->send();
                })
                ->modalSubmitActionLabel('Buchen'),
        ];
    }
}
