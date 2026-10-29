<?php

namespace Modules\Kleiderkammer\src\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Resources\StoreResource\Pages;
use Modules\Kleiderkammer\src\Models\Store;

/** The Lager of the Kleiderkammer (Hauptlager and more); with more than one, Zugang, Abgang and Artikel ask for it */
class StoreResource extends Resource
{
    use KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/lagerorte';

    protected static ?string $model = Store::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Lager verwalten';

    // Under the Lager list
    protected static ?string $navigationParentItem = 'Lager';

    protected static ?string $modelLabel = 'Lager';

    protected static ?string $pluralModelLabel = 'Lager';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')->label('Name')->placeholder('z. B. Lager Gerätehaus Nord')->required()->maxLength(100)->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('notes')->label('Hinweis')->placeholder('z. B. Keller, Regal 3')->maxLength(300),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withCount(['items as pieces_count' => fn ($q) => $q->whereIn('status', ['available', 'issued'])])
                ->withSum('stock as stock_sum', 'quantity'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable()->weight('medium'),
                Tables\Columns\TextColumn::make('notes')->label('Hinweis')->placeholder('–')->toggleable(),
                Tables\Columns\TextColumn::make('pieces_count')->label('Teile mit Code')->sortable()->alignEnd(),
                Tables\Columns\TextColumn::make('stock_sum')->label('Menge')->sortable()->alignEnd()->formatStateUsing(fn ($state) => (int) $state),
            ])
            ->defaultSort('name')
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->before(function (Store $record, Actions\DeleteAction $action) {
                        if ($record->inUse() || Store::count() === 1) {
                            Notification::make()->danger()->title('Lager kann nicht gelöscht werden')
                                ->body(Store::count() === 1 ? 'Es muss mindestens ein Lager geben.' : 'Es liegen noch Teile oder Mengen darin – erst ausgeben, umbuchen oder ausmustern.')->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStores::route('/'),
            'create' => Pages\CreateStore::route('/neu'),
            'edit' => Pages\EditStore::route('/{record}/bearbeiten'),
        ];
    }
}
