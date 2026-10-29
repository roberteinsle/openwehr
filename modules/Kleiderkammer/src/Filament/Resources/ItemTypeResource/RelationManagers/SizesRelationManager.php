<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\MemberSize;
use Modules\Kleiderkammer\src\Models\Size;

class SizesRelationManager extends RelationManager
{
    protected static string $relationship = 'sizes';

    protected static ?string $title = 'Größen';

    protected static ?string $modelLabel = 'Größe';

    protected static ?string $pluralModelLabel = 'Größen';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) $ownerRecord->has_size;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label('Größe')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                    ->where('item_type_id', $this->getOwnerRecord()->getKey())
                    ->where(fn ($q) => filled($get('group_id')) ? $q->where('group_id', $get('group_id')) : $q->whereNull('group_id')))
                ->validationMessages([
                    'unique' => fn (Get $get) => filled($get('group_id'))
                        ? 'Diese Größe gibt es in dieser Gruppe schon.'
                        : 'Diese Größe gibt es für diesen Artikel schon.',
                ]),

            Forms\Components\Select::make('group_id')
                ->label('Gruppe')
                ->options(fn () => $this->getOwnerRecord()->groups()->pluck('name', 'id'))
                ->placeholder('Alle Gruppen')
                ->helperText('Leer lassen, wenn die Größe für alle Gruppen gilt.')
                ->visible(fn () => $this->getOwnerRecord()->groups()->exists()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Größe')->searchable(),
                Tables\Columns\TextColumn::make('group.name')
                    ->label('Gruppe')
                    ->placeholder('Alle')
                    ->visible(fn () => $this->getOwnerRecord()->groups()->exists()),
                Tables\Columns\TextColumn::make('items_count')->label('Einzelteile')->counts('items'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->label('Gruppe')
                    ->options(fn () => ['none' => 'Für alle Gruppen'] + $this->getOwnerRecord()->groups()->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => match (true) {
                        blank($data['value'] ?? null) => $query,
                        $data['value'] === 'none' => $query->whereNull('group_id'),
                        default => $query->where('group_id', $data['value']),
                    })
                    ->visible(fn () => $this->getOwnerRecord()->groups()->exists()),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action
                ->button()
                ->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->paginated(false)
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Größe hinzufügen')
                    ->mutateDataUsing(fn (array $data) => $data + [
                        'sort_order' => (int) $this->getOwnerRecord()->sizes()->max('sort_order') + 1,
                    ]),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->before(function (Size $record, Actions\DeleteAction $action) {
                        if (self::inUse($record)) {
                            Notification::make()
                                ->title('Größe kann nicht gelöscht werden')
                                ->body('Es gibt Teile, Bestand, Ausgabevorgänge oder Kleiderkammerprofile mit dieser Größe.')
                                ->danger()
                                ->send();
                            $action->cancel();
                        }
                    }),
            ])
            // Several at once; sizes still in use stay and are named
            ->toolbarActions([
                Actions\BulkAction::make('deleteSizes')
                    ->label('Löschen')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Markierte Größen löschen?')
                    ->modalDescription('Größen, zu denen es Teile, Bestand, Ausgaben oder Profileinträge gibt, bleiben erhalten.')
                    ->action(function ($records) {
                        [$kept, $free] = $records->partition(fn (Size $size) => self::inUse($size));
                        $free->each->delete();
                        $note = Notification::make()->title($free->count().' '.($free->count() === 1 ? 'Größe' : 'Größen').' gelöscht.');
                        $kept->isEmpty() ? $note->success() : $note->warning()->body('Noch verwendet und deshalb nicht gelöscht: '.$kept->pluck('name')->implode(', ').'.');
                        $note->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /** Pieces, stock, issues or Kleiderkammerprofile with this size */
    public static function inUse(Size $size): bool
    {
        return $size->items()->exists()
            || $size->stock()->exists()
            || IssuedItem::where('size_id', $size->id)->exists()
            || MemberSize::where('size_id', $size->id)->exists();
    }
}
