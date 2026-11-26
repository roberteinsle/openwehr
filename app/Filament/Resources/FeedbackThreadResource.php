<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\FeedbackThreadResource\Pages;
use App\Models\FeedbackThread;
use App\Services\ModuleRegistry;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class FeedbackThreadResource extends Resource
{
    use AdminOnly;

    protected static ?string $slug = 'feedback';

    protected static ?string $model = FeedbackThread::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Feedback';

    protected static ?string $modelLabel = 'Feedback';

    protected static ?string $pluralModelLabel = 'Feedback';

    protected static ?int $navigationSort = 8;

    /** Threads with a member message the admins haven't opened yet */
    public static function getNavigationBadge(): ?string
    {
        $unread = FeedbackThread::where('unread_for_admin', true)->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('unread_for_admin')
                    ->label('')
                    ->icon(fn (bool $state) => $state ? 'heroicon-s-envelope' : null)
                    ->color('danger')
                    ->tooltip(fn (FeedbackThread $record) => $record->unread_for_admin ? 'Ungelesen' : null),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Betreff')
                    ->searchable()
                    ->weight(fn (FeedbackThread $record) => $record->unread_for_admin ? 'bold' : null),

                Tables\Columns\TextColumn::make('module_key')
                    ->label('Bereich')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? (app(ModuleRegistry::class)->get($state)['name'] ?? $state)
                        : 'Allgemein')
                    ->badge()
                    ->color(fn (?string $state): string => $state ? 'primary' : 'gray'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Von')
                    ->searchable(),

                Tables\Columns\TextColumn::make('messages_count')
                    ->label('Nachrichten')
                    ->counts('messages'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === FeedbackThread::STATUS_CLOSED ? 'Erledigt' : 'Offen')
                    ->color(fn (string $state) => $state === FeedbackThread::STATUS_CLOSED ? 'gray' : 'warning'),

                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('Letzte Nachricht')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Berlin')
                    ->sortable(),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        FeedbackThread::STATUS_OPEN => 'Offen',
                        FeedbackThread::STATUS_CLOSED => 'Erledigt',
                    ]),
                Tables\Filters\TernaryFilter::make('unread_for_admin')->label('Ungelesen'),
                Tables\Filters\SelectFilter::make('module_key')
                    ->label('Bereich')
                    ->options(function () {
                        $modules = app(ModuleRegistry::class)->all();
                        $options = ['__none__' => 'Allgemein'];
                        foreach ($modules as $key => $def) {
                            $options[$key] = $def['name'];
                        }

                        return $options;
                    })
                    ->query(fn ($query, array $data) => isset($data['value'])
                        ? ($data['value'] === '__none__'
                            ? $query->whereNull('module_key')
                            : $query->where('module_key', $data['value']))
                        : $query),
            ])
            ->actions([
                Actions\ViewAction::make()->label('Öffnen'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('user.name')->label('Von'),
                    Infolists\Components\TextEntry::make('user.email')->label('E-Mail'),
                    Infolists\Components\TextEntry::make('module_key')
                        ->label('Bereich')
                        ->formatStateUsing(fn (?string $state): string => $state
                            ? (app(ModuleRegistry::class)->get($state)['name'] ?? $state)
                            : 'Allgemein')
                        ->badge()
                        ->color(fn (?string $state): string => $state ? 'primary' : 'gray'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => $state === FeedbackThread::STATUS_CLOSED ? 'Erledigt' : 'Offen')
                        ->color(fn (string $state) => $state === FeedbackThread::STATUS_CLOSED ? 'gray' : 'warning'),
                ]),

            Infolists\Components\RepeatableEntry::make('messages')
                ->label('Verlauf')
                ->columnSpanFull()
                ->schema([
                    Infolists\Components\TextEntry::make('author.name')
                        ->label('')
                        ->formatStateUsing(fn ($state, $record) => ($state ?? 'Unbekannt').($record->from_admin ? ' (Admin)' : ''))
                        ->weight('bold')
                        ->color(fn ($record) => $record->from_admin ? 'danger' : null),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('')
                        ->dateTime('d.m.Y H:i')
                        ->timezone('Europe/Berlin')
                        ->color('gray'),
                    Infolists\Components\TextEntry::make('body')
                        ->label('')
                        ->columnSpanFull()
                        // Keep the member's line breaks; e() escapes the text first
                        ->formatStateUsing(fn (string $state) => nl2br(e($state)))
                        ->html(),
                ])
                ->columns(2),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeedbackThreads::route('/'),
            'view' => Pages\ViewFeedbackThread::route('/{record}'),
        ];
    }
}
