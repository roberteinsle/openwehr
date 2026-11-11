<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\GroupResource\Pages;
use App\Models\Group;
use App\Services\ModuleRegistry;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Table;

class GroupResource extends Resource
{
    use AdminOnly;

    protected static ?string $slug = 'abteilungen';

    protected static ?string $model = Group::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Abteilungen';

    // Sub-menu of Kameraden
    protected static ?string $navigationParentItem = 'Kameraden';

    protected static ?string $modelLabel = 'Abteilung';

    protected static ?string $pluralModelLabel = 'Abteilungen';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('sort_order')
                ->label('Reihenfolge')
                ->numeric()
                ->default(0),

            Section::make('Farbe')
                ->description('So erkennt man die Abteilung in Listen (z. B. Kameraden) auf einen Blick. Vorschlag: Einsatzabteilung Rot mit Dunkelblau, Jugendfeuerwehr Orange und Blau.')
                ->schema([
                    Forms\Components\ColorPicker::make('color')->label('Farbe')->hex()
                        ->regex('/^#[0-9A-Fa-f]{6}$/')->placeholder(Group::DEFAULT_COLOR),
                    Forms\Components\ColorPicker::make('color_secondary')->label('Zweite Farbe (Rand)')->hex()
                        ->regex('/^#[0-9A-Fa-f]{6}$/'),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->columnSpanFull(),

            Section::make('Jugendabteilung')
                ->description('Für die Jugendfeuerwehr (10 bis 18 Jahre): Module, die für Kinder keinen Sinn machen, werden ihnen nicht automatisch freigeschaltet. Ab einem Alter dürfen sie bei den Übungsdiensten einer anderen Abteilung mitmachen; mit dem Übertrittsalter erscheinen sie unter Kameraden → Altersübergänge.')
                ->schema([
                    Forms\Components\Toggle::make('is_youth')->label('Jugendabteilung')->live()->columnSpanFull(),
                    Forms\Components\CheckboxList::make('excluded_modules')
                        ->label('Nicht automatisch freischalten')
                        ->helperText('Gilt für Kameraden, die nur in Abteilungen mit diesem Haken sind. Einzeln freischalten geht weiter (Kamerad bearbeiten → Modul-Zugriff).')
                        ->options(fn () => collect(app(ModuleRegistry::class)->all())->map(fn ($d) => $d['name'])->sort()->all())
                        ->columns(['default' => 1, 'md' => 3])
                        ->columnSpanFull(),
                    Forms\Components\Select::make('guest_group_id')
                        ->label('Ab dem Alter bei Übungsdiensten mitmachen in')
                        ->options(fn (?Group $record) => Group::when($record, fn ($q) => $q->whereKeyNot($record->id))->orderBy('sort_order')->pluck('name', 'id')),
                    Forms\Components\TextInput::make('guest_from_age')->label('ab Alter')->numeric()->minValue(10)->maxValue(25)->placeholder('16'),
                    Forms\Components\Select::make('transfer_group_id')
                        ->label('Übertritt in')
                        ->options(fn (?Group $record) => Group::when($record, fn ($q) => $q->whereKeyNot($record->id))->orderBy('sort_order')->pluck('name', 'id')),
                    Forms\Components\TextInput::make('transfer_age')->label('mit Alter')->numeric()->minValue(10)->maxValue(30)->placeholder('18'),
                ])
                ->columns(['default' => 1, 'md' => 2])
                ->visible(fn (Get $get) => (bool) $get('is_youth'))
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
                    ->sortable()
                    ->badge()
                    ->color(fn (Group $record) => Color::hex($record->colorOrDefault()))
                    ->description(fn (Group $record) => $record->is_youth ? 'Jugendabteilung' : null),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Mitglieder')
                    ->counts('users'),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Reihenfolge')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action
                ->button()
                ->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->actions([
                Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            'create' => Pages\CreateGroup::route('/neu'),
            'edit' => Pages\EditGroup::route('/{record}/bearbeiten'),
        ];
    }
}
