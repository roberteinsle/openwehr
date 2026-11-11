<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\GuardianResource\Pages;
use App\Models\Group;
use App\Models\Guardian;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin → Kameraden → Eltern: every Erziehungsberechtigte(r) with the child, e.g. for the Jugendfeuerwehr.
 * They are kept at the child (Kamerad bearbeiten → Erziehungsberechtigte); this is the list to search.
 */
class GuardianResource extends Resource
{
    use AdminOnly;

    protected static ?string $slug = 'eltern';

    protected static ?string $model = Guardian::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Eltern';

    protected static ?string $navigationParentItem = 'Kameraden';

    protected static ?string $modelLabel = 'Erziehungsberechtigte(r)';

    protected static ?string $pluralModelLabel = 'Eltern';

    // Right above the Ansprechpartner
    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user.groups')->whereHas('user'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Kind')->searchable()->sortable()
                    ->url(fn (Guardian $record) => UserResource::getUrl('edit', ['record' => $record->user_id])),
                Tables\Columns\TextColumn::make('user.groups.name')->label('Abteilung')->badge()->separator(', ')
                    ->color(fn (string $state) => Color::hex(UserResource::groupColors()[$state] ?? Group::DEFAULT_COLOR)),
                Tables\Columns\TextColumn::make('email')->label('E-Mail')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('phone')->label('Telefon')->placeholder('–')->searchable()
                    ->url(fn (Guardian $record) => $record->phone ? 'tel:'.preg_replace('/[^0-9+]/', '', $record->phone) : null),
                Tables\Columns\TextColumn::make('last_login_at')->label('Eltern-Login')->sortable()
                    ->since()->timezone('Europe/Berlin')->placeholder('noch nie'),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('youth')
                    ->label('Kinder in Jugendabteilungen')
                    ->placeholder('Alle')->trueLabel('Nur Jugendabteilungen')->falseLabel('Andere')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('user.groups', fn ($g) => $g->where('is_youth', true)),
                        false: fn (Builder $q) => $q->whereDoesntHave('user.groups', fn ($g) => $g->where('is_youth', true)),
                    ),
                Tables\Filters\SelectFilter::make('group')
                    ->label('Abteilung des Kindes')
                    ->options(fn () => Group::orderBy('sort_order')->pluck('name', 'id'))
                    ->multiple()->searchable()
                    ->query(fn (Builder $q, array $data) => filled($data['values'] ?? null)
                        ? $q->whereHas('user.groups', fn ($g) => $g->whereIn('groups.id', $data['values'])) : $q),
                Tables\Filters\TernaryFilter::make('login')
                    ->label('Eltern-Login')
                    ->placeholder('Alle')->trueLabel('Schon angemeldet')->falseLabel('Noch nie angemeldet')
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('last_login_at'), false: fn (Builder $q) => $q->whereNull('last_login_at')),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Kind aktiv')
                    ->placeholder('Alle')->trueLabel('Nur aktive Kinder')->falseLabel('Nicht aktiv')
                    ->default(true)
                    ->queries(true: fn (Builder $q) => $q->whereHas('user', fn ($u) => $u->where('is_active', true)),
                        false: fn (Builder $q) => $q->whereHas('user', fn ($u) => $u->where('is_active', false))),
            ])
            ->actions([
                Actions\Action::make('child')->label('Kind bearbeiten')->icon('heroicon-o-pencil-square')
                    ->url(fn (Guardian $record) => UserResource::getUrl('edit', ['record' => $record->user_id])),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGuardians::route('/'),
        ];
    }
}
