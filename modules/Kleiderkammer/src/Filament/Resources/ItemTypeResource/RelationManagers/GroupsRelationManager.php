<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Modules\Kleiderkammer\src\Models\ItemGroup;

class GroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'groups';

    protected static ?string $title = 'Gruppen';

    protected static ?string $modelLabel = 'Gruppe';

    protected static ?string $pluralModelLabel = 'Gruppen';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label('Gruppe')
                ->placeholder('z. B. Damen, Herren, S-Guard, Viking')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('item_type_id', $this->getOwnerRecord()->getKey()))
                ->validationMessages(['unique' => 'Diese Gruppe gibt es für diesen Artikel schon.']),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Gruppen gelten nur für diesen Artikel, z. B. Damen/Herren oder Hersteller.')
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Gruppe'),
                Tables\Columns\TextColumn::make('items_count')->label('Einzelteile')->counts('items'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action
                ->button()
                ->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->paginated(false)
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Gruppe hinzufügen')
                    ->mutateDataUsing(fn (array $data) => $data + [
                        'sort_order' => (int) $this->getOwnerRecord()->groups()->max('sort_order') + 1,
                    ]),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->before(function (ItemGroup $record, Actions\DeleteAction $action) {
                        if ($record->isInUse()) {
                            Notification::make()
                                ->title('Gruppe kann nicht gelöscht werden')
                                ->body('Es gibt Größen, Teile, Bestand oder Ausgabevorgänge in dieser Gruppe.')
                                ->danger()
                                ->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }
}
