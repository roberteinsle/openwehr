<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ContactResource\Pages;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Group;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/** Ansprechpartner of the Wehr: every Kamerad sees them under /ansprechpartner, with phone to tap */
class ContactResource extends Resource
{
    use AdminOnly;

    protected static ?string $slug = 'ansprechpartner';

    protected static ?string $model = Contact::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationLabel = 'Ansprechpartner';

    protected static ?string $navigationParentItem = 'Kameraden';

    protected static ?string $modelLabel = 'Ansprechpartner';

    protected static ?string $pluralModelLabel = 'Ansprechpartner';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('role')->label('Aufgabe')->placeholder('z. B. Wehrführer, Jugendwart')->required()->maxLength(150),
            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(150),
            Forms\Components\TextInput::make('phone')->label('Telefon')->tel()->maxLength(50),
            Forms\Components\TextInput::make('email')->label('E-Mail')->email()->maxLength(255),
            Forms\Components\Select::make('group_id')->label('Abteilung (optional)')->options(fn () => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                ->helperText('Zum Gruppieren, z. B. „Jugendfeuerwehr“. Sichtbar sind alle Ansprechpartner für alle.'),
            Forms\Components\Select::make('contact_group_id')->label('Gruppe (optional)')
                ->options(fn () => ContactGroup::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                ->createOptionForm(ContactGroupResource::fields())
                ->createOptionUsing(fn (array $data) => ContactGroup::create($data + ['sort_order' => (int) ContactGroup::max('sort_order') + 1])->getKey())
                ->helperText('Eigene Gruppe statt der Abteilung, z. B. „Extern“ für Leitstelle, Gemeinde, Kreisbrandmeister. Gruppen stehen nach den Ansprechpartnern der Wehr.'),
            Forms\Components\TextInput::make('note')->label('Hinweis')->placeholder('z. B. erreichbar abends')->maxLength(300),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('role')->label('Aufgabe')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Telefon'),
                Tables\Columns\TextColumn::make('group.name')->label('Abteilung'),
                Tables\Columns\TextColumn::make('contactGroup.name')->label('Gruppe'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Actions\Action $action, bool $isReordering) => $action->button()->label($isReordering ? 'Fertig' : 'Reihenfolge ändern'))
            ->filters([Tables\Filters\SelectFilter::make('contact_group_id')->label('Gruppe')->relationship('contactGroup', 'name')])
            ->actions([Actions\EditAction::make(), Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContacts::route('/'),
            'create' => Pages\CreateContact::route('/neu'),
            'edit' => Pages\EditContact::route('/{record}/bearbeiten'),
        ];
    }
}
