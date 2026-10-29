<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use App\Models\User;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Movement;
use Modules\Kleiderkammer\src\Models\Store;

/** The Protokoll: every Zugang, Abgang, Ausgabe, Rücknahme and Korrektur, searchable and filterable */
class MovementsPage extends Page implements HasTable
{
    use InteractsWithTable, KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/protokoll';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Protokoll';

    protected static ?string $title = 'Protokoll';

    protected static ?int $navigationSort = 12;

    protected string $view = 'kleiderkammer::filament.pages.store';

    public function getSubheading(): ?string
    {
        return 'Jede Buchung der Kleiderkammer: Zugang und Abgang im Lager, Ausgabe an und Rücknahme von Kameraden, Korrekturen des Bestands. Einträge lassen sich nicht ändern.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Movement::query()->with('store'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Datum')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->sortable(),
                Tables\Columns\TextColumn::make('kind')->label('Art')->badge()->sortable()
                    ->formatStateUsing(fn (string $state) => Movement::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => Movement::COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('item_name')->label('Artikel')->searchable()->sortable()
                    ->url(fn (Movement $record) => $record->item_type_id && ItemType::whereKey($record->item_type_id)->exists() ? StoreTypePage::getUrl(['type' => $record->item_type_id]) : null),
                Tables\Columns\TextColumn::make('code')->label('Code')->fontFamily('mono')->searchable()->sortable()->placeholder('–'),
                Tables\Columns\TextColumn::make('variant')->label('Gruppe / Größe')->placeholder('–')->toggleable(),
                Tables\Columns\TextColumn::make('quantity')->label('Anzahl')->alignEnd()->sortable()
                    ->formatStateUsing(fn (int $state, Movement $record) => $record->kind === 'korrektur' && $state > 0 ? '+'.$state : (string) $state),
                Tables\Columns\TextColumn::make('store.name')->label('Lager')->sortable()->placeholder('–')->toggleable()->visible(fn () => Store::several()),
                Tables\Columns\TextColumn::make('user_name')->label('Kamerad')->searchable()->sortable()->placeholder('–')
                    ->url(fn (Movement $record) => $record->user_id && User::whereKey($record->user_id)->exists() ? MemberResource::getUrl('edit', ['record' => $record->user_id]) : null),
                Tables\Columns\TextColumn::make('reason')->label('Grund / Notiz')->searchable()->limit(50)->placeholder('–')->wrap(),
                Tables\Columns\TextColumn::make('by_name')->label('Gebucht von')->searchable()->sortable()->placeholder('–')->toggleable(),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->label('Art')->options(Movement::KINDS)->multiple(),
                Tables\Filters\SelectFilter::make('item_type_id')->label('Artikel')->searchable()
                    ->options(fn () => ItemType::orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn () => request()->integer('artikel') ?: null),
                Tables\Filters\SelectFilter::make('store_id')->label('Lager')->options(fn () => Store::options())->visible(fn () => Store::several()),
                Tables\Filters\SelectFilter::make('user_id')->label('Kamerad')->searchable()
                    ->options(fn () => User::whereIn('id', Movement::whereNotNull('user_id')->distinct()->pluck('user_id'))->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\Filter::make('period')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Von'),
                        Forms\Components\DatePicker::make('until')->label('Bis'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, 'Europe/Berlin')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $until) => $q->where('created_at', '<=', Carbon::parse($until, 'Europe/Berlin')->endOfDay()->utc())))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'Ab '.Carbon::parse($data['from'])->format('d.m.Y') : null,
                        ($data['until'] ?? null) ? 'Bis '.Carbon::parse($data['until'])->format('d.m.Y') : null,
                    ])),
            ], layout: Tables\Enums\FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 5])
            ->emptyStateHeading('Noch keine Buchungen')
            ->paginated([25, 50, 100]);
    }
}
