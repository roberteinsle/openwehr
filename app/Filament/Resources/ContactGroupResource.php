<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ContactGroupResource\Pages;
use App\Models\ContactGroup;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/** Free groups of Ansprechpartner (e.g. "Extern"), reached from the Ansprechpartner list */
class ContactGroupResource extends Resource
{
    use AdminOnly;

    protected static ?string $slug = 'ansprechpartner-gruppen';

    protected static ?string $model = ContactGroup::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'Gruppe';

    protected static ?string $pluralModelLabel = 'Ansprechpartner-Gruppen';

    /** @return array<int, Component|Forms\Components\Field> */
    public static function fields(): array
    {
        return [
            Forms\Components\TextInput::make('name')->label('Name')->placeholder('z. B. Extern, Gemeinde, Kreis')->required()->maxLength(100),
            Forms\Components\Toggle::make('on_roster')->label('Im Dienstplan aufführen')->default(true)
                ->helperText('Der gedruckte Dienstplan (Kalender) listet auf Wunsch die Ansprechpartner; ausgeschaltet fehlen die dieser Gruppe dort.'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema(self::fields());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('contacts_count')->label('Ansprechpartner')->counts('contacts'),
                Tables\Columns\IconColumn::make('on_roster')->label('Im Dienstplan')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action->button()->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->actions([Actions\EditAction::make(), Actions\DeleteAction::make()->modalDescription('Die Ansprechpartner der Gruppe bleiben erhalten und stehen danach wieder bei ihrer Abteilung.')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContactGroups::route('/'),
            'create' => Pages\CreateContactGroup::route('/neu'),
            'edit' => Pages\EditContactGroup::route('/{record}/bearbeiten'),
        ];
    }
}
