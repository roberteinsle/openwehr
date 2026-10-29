<?php

namespace Modules\Kleiderkammer\src\Filament\Resources;

use App\Enums\MemberStatus;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource\RelationManagers;
use Modules\Kleiderkammer\src\Services\IssuingService;

// The suite's users seen from the Kleiderkammer. Kleiderwarte edit only the Kleiderkammer profile
// (notes, clothing sizes, equipment); Abteilungen, functions, rank and status stay with the admins (/admin/users).
class MemberResource extends Resource
{
    use KleiderkammerArea;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'kleiderkammer/kameraden';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Kameraden';

    protected static ?string $modelLabel = 'Kamerad';

    protected static ?string $pluralModelLabel = 'Kameraden';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Kamerad')
                ->description('Abteilungen, Funktionen, Dienstgrad und Status pflegen Admins in der Kameradenverwaltung.')
                ->schema([
                    // The photo from the personal profile, when there is one
                    ImageEntry::make('avatar')->hiddenLabel()->circular()->imageSize(72)
                        ->state(fn (User $record) => $record->avatarUrl())
                        ->visible(fn (User $record) => $record->avatarUrl() !== null),
                    TextEntry::make('name')->label('Name'),
                    TextEntry::make('groups_list')->label('Abteilungen')->state(fn (User $record) => $record->groups->pluck('name')->implode(', '))->placeholder('–'),
                    TextEntry::make('functions_list')->label('Funktionen')->state(fn (User $record) => $record->functions->map->fullLabel()->implode(', '))->placeholder('–'),
                    TextEntry::make('rank')->label('Dienstgrad')->placeholder('–'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (MemberStatus $state) => $state->label())
                        ->color(fn (MemberStatus $state) => match ($state) {
                            MemberStatus::Active => 'success',
                            MemberStatus::Inactive => 'warning',
                            MemberStatus::Left => 'danger',
                        }),
                    Forms\Components\Textarea::make('notes')
                        ->label('Notizen')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 4])
                ->columnSpanFull(),
        ]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $options = fn (string $enum) => collect($enum::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('kleiderkammerItems')->with('groups'))
            ->columns([
                Tables\Columns\ImageColumn::make('avatar')
                    ->label('')
                    ->circular()
                    ->imageSize(32)
                    ->state(fn (User $record) => $record->avatarUrl()),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('kleiderkammer_items_count')
                    ->label('Ausgeliehen')
                    ->sortable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notizen')
                    ->limit(40)
                    ->placeholder('–')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('groups')
                    ->label('Abteilung')
                    ->relationship('groups', 'name', fn ($query) => $query->orderBy('sort_order')->orderBy('name'))
                    ->multiple()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options($options(MemberStatus::class)),

                Tables\Filters\Filter::make('has_items')
                    ->label('Mit Ausrüstung')
                    ->query(fn (Builder $query) => $query->has('kleiderkammerItems')),
            ])
            ->recordUrl(fn (User $record) => static::getUrl('edit', ['record' => $record]))
            ->recordActions([
                Actions\EditAction::make()->label('Profil'),
                Actions\Action::make('takeBack')
                    ->label('Alle zurückfordern')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalHeading('Alle Teile zurückfordern?')
                    ->modalDescription('Alle offenen Leihvorgänge dieses Kameraden werden als zurückgegeben markiert.')
                    ->visible(fn (User $record) => $record->kleiderkammer_items_count > 0)
                    ->action(function (User $record) {
                        app(IssuingService::class)->takeBack($record);
                        Notification::make()->title('Alle Teile zurückgefordert.')->success()->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\IssuancesRelationManager::class,
            RelationManagers\SizesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'edit' => Pages\EditMember::route('/{record}/bearbeiten'),
        ];
    }
}
