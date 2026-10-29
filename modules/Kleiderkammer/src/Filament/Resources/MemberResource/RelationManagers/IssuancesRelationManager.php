<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers;

use App\Enums\MemberStatus;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Pages\StorePage;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StoreService;

class IssuancesRelationManager extends RelationManager
{
    protected static string $relationship = 'kleiderkammerIssuances';

    protected static ?string $title = 'Ausrüstung';

    protected static ?string $modelLabel = 'Ausgabe';

    protected static ?string $pluralModelLabel = 'Ausgaben';

    /** Open messages from this member show up as a badge on the tab */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $open = EquipmentMessage::where('user_id', $ownerRecord->getKey())->whereNull('handled_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'danger';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['item.itemType', 'item.size', 'item.group', 'itemType', 'size', 'group'])
                ->withCount(['messages as open_messages_count' => fn (Builder $q) => $q->whereNull('handled_at')]))
            ->columns([
                Tables\Columns\TextColumn::make('article')
                    ->label('Artikel')
                    ->state(fn (IssuedItem $record) => ($record->item?->itemType ?? $record->itemType)?->name ?? '–'),
                Tables\Columns\TextColumn::make('item.code')->label('Code')->fontFamily('mono')->placeholder('Bestand'),
                Tables\Columns\TextColumn::make('group_label')
                    ->label('Gruppe')
                    ->state(fn (IssuedItem $record) => ($record->item?->group ?? $record->group)?->name)
                    ->placeholder('–'),
                Tables\Columns\TextColumn::make('size_label')
                    ->label('Größe')
                    ->state(fn (IssuedItem $record) => ($record->item?->size ?? $record->size)?->name)
                    ->placeholder('–'),
                Tables\Columns\TextColumn::make('open_messages_count')
                    ->label('Nachricht')
                    ->badge()
                    ->color('danger')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state.' offen' : null)
                    ->placeholder('–'),
                Tables\Columns\TextColumn::make('quantity')->label('Menge'),
                Tables\Columns\TextColumn::make('issued_at')->label('Ausgegeben')->date('d.m.Y')->timezone('Europe/Berlin')->sortable(),
                Tables\Columns\TextColumn::make('returned_at')->label('Zurück')->date('d.m.Y')->timezone('Europe/Berlin')->placeholder('–'),
            ])
            ->defaultSort('issued_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('open')
                    ->label('Anzeigen')
                    ->placeholder('Alle')
                    ->trueLabel('Nur aktuell ausgegeben')
                    ->falseLabel('Nur zurückgegeben')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('returned_at'),
                        false: fn (Builder $query) => $query->whereNotNull('returned_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->headerActions([
                $this->issueTrackedAction(),
                $this->issueStockAction(),
            ])
            ->recordActions([
                Actions\Action::make('messages')
                    ->label('Nachrichten')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('gray')
                    ->visible(fn (IssuedItem $record) => $record->messages()->exists())
                    ->modalHeading('Nachrichten zu diesem Artikel')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Schließen')
                    ->modalContent(fn (IssuedItem $record) => new HtmlString(
                        $record->messages()->latest()->get()->map(fn (EquipmentMessage $m) => '<div style="margin-bottom:1rem">'
                            .'<p style="font-size:.75rem;opacity:.7">'.e($m->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i')).' · '.e($m->kindLabel()).' · '.e($m->stateLabel()).'</p>'
                            .'<p style="white-space:pre-line">'.e($m->body).'</p></div>')->implode('')
                    )),
                Actions\Action::make('return')
                    ->label('Zurücknehmen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->visible(fn (IssuedItem $record) => $record->isOpen())
                    ->action(function (IssuedItem $record) {
                        app(IssuingService::class)->return($record);
                        Notification::make()->title('Zurückgenommen.')->success()->send();
                    }),
                // The piece is worn out or lost: the loan ends and it doesn't go back into the Lager
                Actions\Action::make('dispose')
                    ->label('Ausmustern')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (IssuedItem $record) => $record->isOpen())
                    ->modalHeading('Ausmustern statt zurücknehmen')
                    ->modalDescription('Das Teil kommt nicht zurück ins Lager, sondern verlässt die Kleiderkammer (z. B. verschlissen, defekt, verloren).')
                    ->modalSubmitActionLabel('Ausmustern')
                    ->schema([StorePage::reasonField()])
                    ->action(function (IssuedItem $record, array $data) {
                        app(StoreService::class)->disposeIssued($record, (string) $data['reason'], auth()->user());
                        Notification::make()->title('Ausgemustert.')->warning()->send();
                    }),
            ]);
    }

    private function issueTrackedAction(): Actions\Action
    {
        return Actions\Action::make('issueTracked')
            ->label('Teil ausgeben')
            ->icon('heroicon-o-qr-code')
            ->visible(fn () => $this->getOwnerRecord()->status === MemberStatus::Active)
            ->schema([
                Forms\Components\Select::make('item_id')
                    ->label('Teil')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::searchAvailableItems($search))
                    ->getOptionLabelUsing(fn ($value) => ($item = Item::find($value)) ? static::itemLabel($item) : null)
                    ->required()
                    ->helperText('Code, Artikelname, Gruppe oder Größe eintippen (z. B. „Jacke L“) oder mit dem Handscanner scannen. Angeboten werden nur verfügbare Teile.'),
                Forms\Components\Textarea::make('notes')->label('Notizen')->rows(2),
            ])
            ->action(function (array $data, Actions\Action $action) {
                try {
                    app(IssuingService::class)->issueTracked($this->getOwnerRecord(), Item::findOrFail($data['item_id']), $data['notes'] ?? '');
                } catch (\DomainException) {
                    Notification::make()->title('Das Teil ist nicht mehr verfügbar.')->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Teil ausgegeben.')->success()->send();
            });
    }

    private function issueStockAction(): Actions\Action
    {
        $profileSize = fn (?int $typeId) => $typeId ? $this->getOwnerRecord()->kleiderkammerSizes()->where('item_type_id', $typeId)->first() : null;

        return Actions\Action::make('issueStock')
            ->label('Aus Bestand ausgeben')
            ->icon('heroicon-o-archive-box')
            ->visible(fn () => $this->getOwnerRecord()->status === MemberStatus::Active)
            ->schema([
                Forms\Components\Select::make('item_type_id')
                    ->label('Artikeltyp')
                    ->options(fn () => ItemType::where('is_tracked', false)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) use ($profileSize) {
                        $profile = $profileSize($state ? (int) $state : null);
                        $set('group_id', $profile?->group_id);
                        $set('size_id', $profile?->size_id);
                    }),
                Forms\Components\Select::make('group_id')
                    ->label('Gruppe')
                    ->options(fn (Get $get) => ItemGroup::where('item_type_id', $get('item_type_id'))->orderBy('sort_order')->pluck('name', 'id'))
                    ->live()
                    ->visible(fn (Get $get) => filled($get('item_type_id')) && ItemGroup::where('item_type_id', $get('item_type_id'))->exists()),
                Forms\Components\Select::make('size_id')
                    ->label('Größe')
                    ->options(fn (Get $get) => Size::where('item_type_id', $get('item_type_id'))
                        ->forGroup($get('group_id'))
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (Size $size) => [$size->id => $size->name.' ('.static::stockOf($get('item_type_id'), $size->id, $get('group_id')).' auf Lager)'])
                        ->all())
                    ->helperText(fn (Get $get) => ($profile = $profileSize($get('item_type_id') ? (int) $get('item_type_id') : null))
                        ? 'Laut Profil: '.$profile->size->name.($profile->group ? ' ('.$profile->group->name.')' : '')
                        : null)
                    ->visible(fn (Get $get) => filled($get('item_type_id')) && Size::where('item_type_id', $get('item_type_id'))->exists()),
                Forms\Components\TextInput::make('quantity')->label('Anzahl')->numeric()->minValue(1)->default(1)->required(),
                Forms\Components\Textarea::make('notes')->label('Notizen')->rows(2),
            ])
            ->action(function (array $data, Actions\Action $action) {
                try {
                    app(IssuingService::class)->issueStock(
                        $this->getOwnerRecord(),
                        ItemType::findOrFail($data['item_type_id']),
                        isset($data['size_id']) ? Size::find($data['size_id']) : null,
                        (int) $data['quantity'],
                        $data['notes'] ?? '',
                        isset($data['group_id']) ? ItemGroup::find($data['group_id']) : null,
                    );
                } catch (\DomainException) {
                    Notification::make()->title('Nicht genug Bestand.')->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Aus Bestand ausgegeben.')->success()->send();
            });
    }

    /** @return array<int, string> available items whose code, type, group or size match every word */
    public static function searchAvailableItems(string $search): array
    {
        $words = array_filter(preg_split('/\s+/', trim($search)));

        return Item::with(['itemType', 'size', 'group'])
            ->where('status', ItemStatus::Available->value)
            ->where(function (Builder $query) use ($words) {
                foreach ($words as $word) {
                    $query->where(fn (Builder $q) => $q
                        ->where('code', 'ilike', "%{$word}%")
                        ->orWhereHas('itemType', fn (Builder $t) => $t->where('name', 'ilike', "%{$word}%"))
                        ->orWhereHas('group', fn (Builder $g) => $g->where('name', 'ilike', "%{$word}%"))
                        ->orWhereHas('size', fn (Builder $z) => $z->where('name', 'ilike', "%{$word}%")));
                }
            })
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Item $item) => [$item->id => static::itemLabel($item)])
            ->all();
    }

    private static function itemLabel(Item $item): string
    {
        return collect([$item->code.' – '.$item->itemType?->name, $item->group?->name, $item->size?->name])->filter()->implode(' · ');
    }

    private static function stockOf(mixed $typeId, int $sizeId, mixed $groupId): int
    {
        return (int) Stock::where('item_type_id', $typeId)->where('size_id', $sizeId)->where('group_id', $groupId ?: null)->value('quantity');
    }
}
