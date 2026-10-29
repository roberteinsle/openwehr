<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;

class SizesRelationManager extends RelationManager
{
    protected static string $relationship = 'kleiderkammerSizes';

    protected static ?string $title = 'Konfektionsgrößen';

    protected static ?string $modelLabel = 'Konfektionsgröße';

    protected static ?string $pluralModelLabel = 'Konfektionsgrößen';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('item_type_id')
                ->label('Artikel')
                ->options(fn () => ItemType::where('has_size', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set) {
                    $set('group_id', null);
                    $set('size_id', null);
                })
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('user_id', $this->getOwnerRecord()->getKey()))
                ->validationMessages(['unique' => 'Für diesen Artikel ist schon eine Größe hinterlegt.']),

            Forms\Components\Select::make('group_id')
                ->label('Gruppe')
                ->options(fn (Get $get) => ItemGroup::where('item_type_id', $get('item_type_id'))->orderBy('sort_order')->pluck('name', 'id'))
                ->live()
                ->visible(fn (Get $get) => filled($get('item_type_id')) && ItemGroup::where('item_type_id', $get('item_type_id'))->exists()),

            Forms\Components\Select::make('size_id')
                ->label('Größe')
                ->options(fn (Get $get) => Size::where('item_type_id', $get('item_type_id'))->forGroup($get('group_id'))->orderBy('sort_order')->pluck('name', 'id'))
                ->required()
                ->visible(fn (Get $get) => filled($get('item_type_id'))),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Die hinterlegte Größe wird beim Ausgeben aus dem Bestand vorgeschlagen.')
            ->columns([
                Tables\Columns\TextColumn::make('itemType.name')->label('Artikel')->sortable(),
                Tables\Columns\TextColumn::make('group.name')->label('Gruppe')->placeholder('–'),
                Tables\Columns\TextColumn::make('size.name')->label('Größe'),
            ])
            ->paginated(false)
            ->headerActions([
                Actions\CreateAction::make()->label('Größe hinterlegen'),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ]);
    }
}
