<?php

namespace App\Filament\Resources;

use App\Enums\MemberGender;
use App\Enums\MemberStatus;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\UserResource\Pages;
use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use App\Services\EmailChangeService;
use App\Services\Impersonation;
use App\Services\Invitation;
use App\Services\MagicLinkService;
use App\Services\MemberRemoval;
use App\Services\ModuleRegistry;
use App\Support\FireRanks;
use App\Support\MemberFields;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserResource extends Resource
{
    use AdminOnly;

    protected static ?string $slug = 'kameraden';

    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Kameraden';

    protected static ?string $modelLabel = 'Kamerad';

    protected static ?string $pluralModelLabel = 'Kameraden';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        $registry = app(ModuleRegistry::class);
        $modules = $registry->all();

        return $schema->schema([
            Forms\Components\TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('email')
                ->label('E-Mail-Adresse')
                ->email()
                ->unique(ignoreRecord: true)
                ->maxLength(255)
                ->requiredIf('is_admin', true)
                ->validationMessages(['required_if' => 'Admins brauchen eine E-Mail-Adresse zum Anmelden.'])
                ->helperText(function (?User $record) {
                    $pending = $record ? app(EmailChangeService::class)->pending($record) : null;

                    return $pending
                        ? 'Der Kamerad hat '.$pending->new_email.' beantragt, noch nicht bestätigt. Eine Änderung hier gilt sofort, ohne Bestätigung.'
                        : 'Admins ändern die Adresse sofort, ohne Bestätigung. Login-Links gehen an diese Adresse. Leer lassen für Mitglieder ohne Login (z. B. Kinder der Jugendfeuerwehr, Musikzug): Sie werden in den Modulen geführt (Kleidung, Unterweisungen, Verbandbuch …), können sich aber nicht anmelden und bekommen keine Mails.';
                }),

            Forms\Components\TextInput::make('phone')
                ->label('Mobiltelefon')
                ->tel()
                ->maxLength(30)
                ->helperText('Optional – erscheint bei Buchungskonflikten für andere Kameraden; vorgesehen für spätere SMS-Benachrichtigungen')
                ->visible(MemberFields::PHONE),

            // Typed as TT.MM.JJJJ without a calendar; stored as a date
            Forms\Components\TextInput::make('birth_date')
                ->label('Geburtsdatum')
                ->placeholder('TT.MM.JJJJ')
                ->mask('99.99.9999')
                ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state)->format('d.m.Y') : null)
                ->dehydrateStateUsing(fn ($state) => static::isGermanDate($state) ? Carbon::createFromFormat('!d.m.Y', $state)->toDateString() : (filled($state) ? $state : null))
                ->rules(['nullable', 'date_format:d.m.Y', fn () => function ($attribute, $value, $fail) {
                    $date = static::isGermanDate($value) ? Carbon::createFromFormat('!d.m.Y', $value) : null;
                    if ($date && ($date->isFuture() || $date->year < 1900)) {
                        $fail('Bitte gib ein gültiges Geburtsdatum an.');
                    }
                }])
                ->validationMessages(['date_format' => 'Bitte im Format TT.MM.JJJJ eingeben, z. B. 17.05.1990.'])
                ->helperText('Optional. Tag und Monat erscheinen auf der Startseite, wenn der Kamerad das nicht im Profil abgeschaltet hat.')
                ->visible(MemberFields::BIRTH_DATE),

            // Switched off in MemberFields: hidden fields are not saved, so stored values stay
            Forms\Components\TextInput::make('street')
                ->label('Straße und Hausnummer')
                ->maxLength(255)
                ->visible(MemberFields::ADDRESS),

            Forms\Components\TextInput::make('postal_code')
                ->label('Postleitzahl')
                ->maxLength(10)
                ->visible(MemberFields::ADDRESS),

            Forms\Components\TextInput::make('city')
                ->label('Ort')
                ->maxLength(255)
                ->visible(MemberFields::ADDRESS),

            Forms\Components\Toggle::make('is_admin')
                ->label('Administrator')
                ->helperText('Admins haben Zugriff auf das Admin-Backend und können alle Buchungen bearbeiten'),

            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(static::enumOptions(MemberStatus::class))
                ->default(MemberStatus::Active->value)
                ->required()
                ->selectablePlaceholder(false)
                ->helperText('Nur aktive Kameraden können sich anmelden'),

            Section::make('Mitgliedsdaten')
                ->schema([
                    Forms\Components\CheckboxList::make('groups')
                        ->label('Abteilungen')
                        ->relationship('groups', 'name', fn ($query) => $query->orderBy('sort_order')->orderBy('name'))
                        ->helperText('Wo das Mitglied Mitglied ist, z. B. Einsatzabteilung oder Jugendfeuerwehr.')
                        ->columns(['default' => 1, 'md' => 3])
                        ->columnSpanFull(),

                    Forms\Components\Select::make('gender')
                        ->label('Geschlecht')
                        ->options(static::enumOptions(MemberGender::class))
                        ->live()
                        ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set) {
                            // Keep the rank when the gender changes: the same step on the other list
                            $set('rank', FireRanks::translate($get('rank'), $old, $state));
                        }),

                    // Männlich/weiblich: feste Dienstgrad-Liste, sonst Freitext
                    Forms\Components\Select::make('rank')
                        ->label('Dienstgrad')
                        ->options(function (Get $get, ?User $record) {
                            $options = FireRanks::options($get('gender'));

                            // An older free-text rank stays selectable until someone picks a proper one
                            return filled($record?->rank) && ! isset($options[$record->rank])
                                ? [$record->rank => $record->rank.' (bisher)'] + $options
                                : $options;
                        })
                        ->rule(fn (Get $get, ?User $record) => Rule::in(array_unique([...FireRanks::for($get('gender')) ?? [], $record?->rank])))
                        ->searchable()
                        ->placeholder('Dienstgrad wählen')
                        ->visible(fn (Get $get) => FireRanks::for($get('gender')) !== null),

                    Forms\Components\TextInput::make('rank')
                        ->label('Dienstgrad')
                        ->maxLength(255)
                        ->visible(fn (Get $get) => FireRanks::for($get('gender')) === null),

                    static::germanDate('rank_since', 'Im Dienstgrad seit', 'Für Beförderungsvoraussetzungen (Zeit im Dienstgrad).'),
                    static::germanDate('joined_at', 'Eintritt in die Feuerwehr', 'Für Beförderungsvoraussetzungen (Dienstzeit).'),

                    Forms\Components\Select::make('functions')
                        ->label('Funktionen')
                        ->relationship(
                            'functions',
                            'name',
                            fn ($query) => $query->assignable()->with('parent')->orderBy('parent_id')->orderBy('sort_order'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (MemberFunction $record) => $record->fullLabel())
                        // Search the label as shown ("Funktion › Unterfunktion"), so "Jugend" finds the sub-functions of "Jugendwart"
                        ->getSearchResultsUsing(fn (string $search) => MemberFunction::assignable()->with('parent')
                            ->where(fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')
                                ->orWhereHas('parent', fn ($p) => $p->where('name', 'ilike', '%'.$search.'%')))
                            ->orderBy('parent_id')->orderBy('sort_order')->limit(50)->get()
                            ->mapWithKeys(fn (MemberFunction $f) => [$f->id => $f->fullLabel()])->all())
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->helperText('Was das Mitglied macht, z. B. Jugendwart. Gewählt werden Unterfunktionen; eine Funktion ohne Unterfunktion steht direkt zur Wahl.')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notizen')
                        ->rows(3)
                        ->helperText('Nur für Admins und Kleiderwarte sichtbar, z. B. Hinweise zur Ausrüstung')
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->columnSpanFull(),

            Section::make('Erziehungsberechtigte')
                ->description('Z. B. für die Jugendfeuerwehr: Sie bekommen die Kalender-Mails des Kameraden (neue, geänderte und abgesagte Termine, Erinnerungen) und können ohne eigenes Konto über einen Link für ihn zu- oder absagen.')
                ->schema([
                    Forms\Components\Repeater::make('guardians')
                        ->relationship()
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\TextInput::make('name')->label('Name')->required()->maxLength(150),
                            Forms\Components\TextInput::make('email')->label('E-Mail')->email()->required()->maxLength(255)->distinct(),
                            Forms\Components\TextInput::make('phone')->label('Telefon')->tel()->maxLength(50),
                        ])
                        ->columns(['default' => 1, 'md' => 3])
                        ->defaultItems(0)
                        ->maxItems(4)
                        ->addActionLabel('Erziehungsberechtigte(n) hinzufügen')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(fn (?User $record) => ! $record?->guardians()->exists())
                ->columnSpanFull(),

            Section::make('Modul-Zugriff')
                ->description('Welche Module dieser Kamerad verwenden darf.')
                ->schema(static function () use ($modules) {
                    if (empty($modules)) {
                        return [
                            Forms\Components\Placeholder::make('no_modules')
                                ->hiddenLabel()
                                ->content('Keine Module sind registriert.'),
                        ];
                    }

                    $fields = [];
                    foreach ($modules as $key => $def) {
                        $fields[] = Forms\Components\Toggle::make("module_access_{$key}")
                            ->label($def['name'])
                            ->helperText($def['description'])
                            ->live()
                            ->afterStateHydrated(function (Forms\Components\Toggle $component, ?User $record) use ($key) {
                                if ($record) {
                                    $component->state(
                                        DB::table('module_user')
                                            ->where('user_id', $record->id)
                                            ->where('module', $key)
                                            ->exists()
                                    );
                                } else {
                                    $component->state(Module::find($key)?->auto_assign ?? false);
                                }
                            });

                        // A module only for e.g. the Vorstand: someone else needs a confirmation
                        if (app(ModuleRegistry::class)->isRestricted($key)) {
                            $fields[] = Forms\Components\Checkbox::make("module_confirm_{$key}")
                                ->label('Trotzdem freischalten: nicht im '.$def['audience_label'])
                                ->helperText('„'.$def['name'].'“ ist nur für den '.$def['audience_label'].' (inkl. Unterfunktionen) und Admins gedacht.')
                                ->accepted()
                                ->validationMessages(['accepted' => 'Bitte bestätigen oder den Zugang ausschalten.'])
                                ->dehydrated(false)
                                ->visible(fn (Get $get, ?User $record) => (bool) $get("module_access_{$key}") && ! $get('is_admin')
                                    && ! ($record && app(ModuleRegistry::class)->isMeantFor($key, $record))
                                    && ! ($record && DB::table('module_user')->where('user_id', $record->id)->where('module', $key)->exists()));
                        }

                        if (! empty($def['roles'])) {
                            $roleOptions = array_combine($def['roles'], array_map('mb_ucfirst', $def['roles']));
                            $fields[] = Forms\Components\Select::make("module_role_{$key}")
                                ->label('Rolle in '.$def['name'])
                                ->options($roleOptions)
                                ->afterStateHydrated(function (Forms\Components\Select $component, ?User $record) use ($key) {
                                    $component->state(
                                        $record
                                            ? DB::table('module_user')
                                                ->where('user_id', $record->id)
                                                ->where('module', $key)
                                                ->value('role')
                                            : null
                                    );
                                })
                                ->visible(fn (Get $get) => (bool) $get("module_access_{$key}"));
                        }
                    }

                    return $fields;
                })
                ->visible(fn () => ! empty($modules))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $registry = app(ModuleRegistry::class);
        $modules = $registry->all();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['groups', 'functions.parent']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('E-Mail')
                    ->placeholder('ohne Login')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('groups.name')
                    ->label('Abteilungen')
                    ->badge()
                    ->separator(', ')
                    // By the first Abteilung in the configured order; Kameraden without one last
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw(
                        '(select min(groups.sort_order) from groups join group_user on group_user.group_id = groups.id where group_user.user_id = users.id) '.($direction === 'desc' ? 'desc' : 'asc').' nulls last'
                    )),

                Tables\Columns\TextColumn::make('functions')
                    ->label('Funktionen')
                    ->badge()
                    ->color('gray')
                    // "Maschinist › HLF" for sub-functions, like in the form
                    ->state(fn (User $record) => $record->functions->map(fn (MemberFunction $f) => $f->fullLabel())->sort()->values()->all())
                    ->placeholder('–')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn (MemberStatus $state) => $state->label())
                    ->color(fn (MemberStatus $state) => match ($state) {
                        MemberStatus::Active => 'success',
                        MemberStatus::Inactive => 'warning',
                        MemberStatus::Left => 'danger',
                    }),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Letzter Login')
                    ->dateTime('d.m.Y H:i')
                    ->timezone('Europe/Berlin')
                    ->placeholder('Noch nie')
                    // "Noch nie" always at the end
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw('last_login_at '.($direction === 'asc' ? 'asc' : 'desc').' nulls last')),
            ])
            // Most recent login first
            ->defaultSort('last_login_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('groups')
                    ->label('Abteilung')
                    ->relationship('groups', 'name', fn ($query) => $query->orderBy('sort_order'))
                    ->multiple()
                    ->preload(),

                Tables\Filters\Filter::make('module_access')
                    ->label('Modul-Zugriff')
                    ->form([
                        Forms\Components\Select::make('module')
                            ->label('Modul')
                            ->options(array_map(fn ($def) => $def['name'], $modules))
                            ->placeholder('Alle Module'),
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['module'])) {
                            $key = $data['module'];
                            $query->where(fn ($q) => $q
                                ->where('is_admin', true)
                                ->orWhereHas('moduleAccess', fn ($q2) => $q2->where('module', $key))
                            );
                        }
                    })
                    ->visible(fn () => ! empty($modules)),

                Tables\Filters\TernaryFilter::make('is_admin')->label('Administrator'),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(static::enumOptions(MemberStatus::class)),
                // A function with sub-functions stands for all of them, e.g. "Vorstand" shows the whole Vorstand
                Tables\Filters\SelectFilter::make('functions')
                    ->label('Funktion')
                    ->options(fn () => static::functionFilterOptions())
                    ->query(fn (Builder $query, array $data) => filled($data['values'] ?? null)
                        ? $query->whereHas('functions', fn ($q) => $q->whereIn('member_functions.id', MemberFunction::idsWithChildren($data['values'])))
                        : $query)
                    ->multiple()
                    ->preload(),
            ])
            ->actions([
                static::impersonateAction(Actions\Action::make('impersonate'))
                    ->iconButton()
                    ->tooltip('Als Kamerad ansehen'),

                // Einladung: QR code to scan in the Wache, or the link by mail (valid for a short while, once)
                Actions\Action::make('invite')
                    ->label('Einladung')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Einladung (QR-Code)')
                    ->visible(fn (User $record) => $record->is_active && $record->hasLogin())
                    ->modalHeading(fn (User $record) => 'Einladung für '.$record->name)
                    ->modalContent(fn (User $record) => view('filament.invite', ['user' => $record, 'invite' => app(Invitation::class)->create($record)]))
                    ->modalSubmitActionLabel('Per E-Mail schicken')
                    ->action(function (User $record, Invitation $invitation) {
                        $invitation->send($record, auth()->user());
                        Notification::make()->title('Einladung an '.$record->email.' geschickt')->success()->send();
                    }),

                Actions\Action::make('send_login_link')
                    ->label('Login-Link senden')
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Login-Link senden')
                    ->visible(fn (User $record) => $record->hasLogin())
                    ->action(function (User $record, MagicLinkService $service) {
                        if (! $record->is_active) {
                            Notification::make()
                                ->title('Kamerad ist deaktiviert')
                                ->danger()
                                ->send();

                            return;
                        }
                        try {
                            $service->sendLink($record->email, request());
                            Notification::make()
                                ->title('Login-Link gesendet')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Fehler beim Senden')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Bearbeiten'),
            ])
            // At the start of the row, so they are visible without scrolling the wide table
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                static::grantModuleAction(Actions\BulkAction::make('grant_module'), $modules)
                    ->action(function (Collection $records, array $data): void {
                        static::grantModule($records->pluck('id'), $data);
                    })
                    ->deselectRecordsAfterCompletion(),

                static::revokeModuleAction(Actions\BulkAction::make('revoke_module'), $modules)
                    ->action(function (Collection $records, array $data): void {
                        static::revokeModule($records->pluck('id'), $data['module']);
                    })
                    ->deselectRecordsAfterCompletion(),

                Actions\DeleteBulkAction::make()
                    ->action(function (Collection $records): void {
                        $kept = $records->reject(fn (User $user) => static::deleteIfUnreferenced($user));

                        $kept->isEmpty()
                            ? Notification::make()->title('Gelöscht')->success()->send()
                            : static::notifyNotDeletable($kept->pluck('name')->implode(', '));
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/neu'),
            'edit' => Pages\EditUser::route('/{record}/bearbeiten'),
        ];
    }

    /**
     * Extract module access data from form, save to module_user, return cleaned data.
     * Called by EditUser and CreateUser pages.
     */
    // Bookings, comments and issued equipment keep a restrictive foreign key to users;
    // a savepoint keeps a failed delete from aborting the surrounding transaction.
    public static function deleteIfUnreferenced(User $user): bool
    {
        try {
            DB::transaction(fn () => $user->delete());

            return true;
        } catch (QueryException $e) {
            if (! in_array($e->getCode(), ['23001', '23503'], true)) {
                throw $e;
            }

            return false;
        }
    }

    public static function notifyNotDeletable(string $names): void
    {
        Notification::make()
            ->title('Nicht gelöscht: '.$names)
            ->body('An diesen Nutzern hängen noch Buchungen, Nachrichten, Ausrüstung oder Einträge im Verbandbuch. Setze den Status stattdessen auf „Ausgetreten“ – oder lösche einzeln mit „Löschen mit Übertragung“ (im Kamerad oben).')
            ->danger()
            ->persistent()
            ->send();
    }

    /** A date typed as TT.MM.JJJJ without a calendar, stored as a date; not in the future */
    public static function germanDate(string $name, string $label, ?string $helper = null): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->label($label)
            ->placeholder('TT.MM.JJJJ')
            ->mask('99.99.9999')
            ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state)->format('d.m.Y') : null)
            ->dehydrateStateUsing(fn ($state) => static::isGermanDate($state) ? Carbon::createFromFormat('!d.m.Y', $state)->toDateString() : (filled($state) ? $state : null))
            ->rules(['nullable', 'date_format:d.m.Y', fn () => function ($attribute, $value, $fail) {
                $date = static::isGermanDate($value) ? Carbon::createFromFormat('!d.m.Y', $value) : null;
                if ($date && ($date->isFuture() || $date->year < 1900)) {
                    $fail('Bitte gib ein gültiges Datum an.');
                }
            }])
            ->validationMessages(['date_format' => 'Bitte im Format TT.MM.JJJJ eingeben, z. B. 01.04.2010.'])
            ->helperText($helper);
    }

    /** TT.MM.JJJJ with a real calendar date behind it */
    private static function isGermanDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
            return false;
        }

        [$day, $month, $year] = array_map('intval', explode('.', $value));

        return checkdate($month, $day, $year);
    }

    /** @param  class-string<\BackedEnum>  $enum */
    private static function enumOptions(string $enum): array
    {
        return collect($enum::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all();
    }

    /** "Als Kamerad ansehen": in the Kameraden list and on "Kamerad bearbeiten" */
    /**
     * "Löschen mit Übertragung": for a Kamerad that data hangs on. Everything goes to the chosen
     * Kamerad (MemberRemoval), then this one is deleted.
     */
    public static function transferDeleteAction(Actions\Action $action): Actions\Action
    {
        return $action
            ->label('Löschen mit Übertragung')
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('danger')
            ->visible(fn (User $record) => ! $record->is(auth()->user()))
            ->modalHeading(fn (User $record) => $record->name.' löschen und Daten übertragen')
            ->modalDescription('Für Kameraden, die nicht nur austreten, sondern gelöscht werden müssen. Alles, was an ihnen hängt, geht vorher auf den gewählten Kameraden über. Das lässt sich nicht rückgängig machen.')
            ->modalSubmitActionLabel('Übertragen und löschen')
            ->schema(fn (User $record) => [
                Forms\Components\Placeholder::make('what')
                    ->label('Wird übertragen')
                    ->content(function () use ($record) {
                        $areas = app(MemberRemoval::class)->preview($record);

                        return $areas === [] ? 'Nichts – es hängen keine Daten an diesem Kameraden.'
                            : collect($areas)->map(fn (int $count, string $area) => $area.': '.$count)->implode(' · ');
                    }),
                Forms\Components\Placeholder::make('stays')
                    ->label('Bleibt nicht')
                    ->content('Zugänge, Abteilungen, Funktionen, Lehrgänge, Atemschutz-Daten, eigene Rückmeldungen, Schichten und das Profilbild werden mit dem Kameraden gelöscht. Im Verbandbuch und in den Logs wird nicht übertragen: dort bleibt der Name stehen, nur die Verknüpfung entfällt.'),
                Forms\Components\Select::make('to')
                    ->label('Übertragen auf')
                    ->helperText('Tipp: einen Kameraden wie „Ehemalige Mitglieder“ (Status ausgetreten) anlegen und dorthin übertragen, damit nichts bei einem aktiven Kameraden landet.')
                    ->options(fn () => User::whereKeyNot($record->id)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                Forms\Components\Checkbox::make('confirm')
                    ->label(fn () => $record->name.' endgültig löschen')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Bitte bestätigen.']),
            ])
            ->action(function (User $record, array $data) {
                try {
                    $areas = app(MemberRemoval::class)->transferAndDelete($record, User::findOrFail($data['to']), auth()->user());
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return null;
                }

                Notification::make()->title($record->name.' gelöscht')
                    ->body($areas === [] ? null : 'Übertragen: '.collect($areas)->map(fn (int $count, string $area) => $area.' '.$count)->implode(', '))
                    ->success()->send();

                return redirect(static::getUrl('index'));
            });
    }

    public static function impersonateAction(Actions\Action $action): Actions\Action
    {
        return $action
            ->label('Als Kamerad ansehen')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (User $record) => ! $record->is_admin && $record->is_active && ! $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => 'App als '.$record->name.' ansehen?')
            ->modalDescription('Du bist dann als dieser Kamerad angemeldet und siehst die App genau so. Alles, was du tust, geschieht in seinem Namen. Oben steht „Zurück zum Admin“.')
            ->modalSubmitActionLabel('Ansehen')
            ->action(function (User $record, Impersonation $impersonation) {
                $impersonation->start(auth()->user(), $record);

                return redirect()->route('home');
            });
    }

    /**
     * "Modul freischalten": module and (if the module has roles) role. Shared by the bulk action on the
     * selection and the header action on everything the filters currently show.
     */
    public static function grantModuleAction(Actions\Action $action, array $modules): Actions\Action
    {
        return $action
            ->label('Module freischalten')
            ->icon('heroicon-o-plus-circle')
            ->schema(static function () use ($modules): array {
                $fields = [
                    Forms\Components\CheckboxList::make('modules')
                        ->label('Module')
                        ->options(array_map(fn ($def) => $def['name'], $modules))
                        ->columns(2)
                        ->bulkToggleable()
                        ->required()
                        ->validationMessages(['required' => 'Bitte mindestens ein Modul ankreuzen.'])
                        ->live(),
                ];

                // A role for each ticked module that has roles; empty keeps a role someone already has
                foreach ($modules as $key => $def) {
                    if (! empty($def['roles'])) {
                        $fields[] = Forms\Components\Select::make("role_{$key}")
                            ->label('Rolle in „'.$def['name'].'“')
                            ->options(array_combine($def['roles'], array_map('mb_ucfirst', $def['roles'])))
                            ->placeholder('Ohne Rolle')
                            ->visible(fn (Get $get) => in_array($key, (array) $get('modules'), true));
                    }
                }

                return $fields;
            })
            ->visible(fn () => ! empty($modules));
    }

    public static function revokeModuleAction(Actions\Action $action, array $modules): Actions\Action
    {
        return $action
            ->label('Modul entziehen')
            ->icon('heroicon-o-minus-circle')
            ->color('danger')
            ->schema([
                Forms\Components\Select::make('module')
                    ->label('Modul')
                    ->options(array_map(fn ($def) => $def['name'], $modules))
                    ->required(),
            ])
            ->requiresConfirmation()
            ->visible(fn () => ! empty($modules));
    }

    /** @param  iterable<int>  $userIds */
    public static function grantModule(iterable $userIds, array $data): void
    {
        $keys = array_values((array) ($data['modules'] ?? $data['module'] ?? []));
        $userIds = collect($userIds)->all();

        DB::transaction(function () use ($keys, $userIds, $data) {
            foreach ($keys as $key) {
                $role = filled($data["role_{$key}"] ?? null) ? $data["role_{$key}"] : null;
                foreach ($userIds as $userId) {
                    $exists = DB::table('module_user')->where('user_id', $userId)->where('module', $key);
                    if ($exists->exists()) {
                        // Without a chosen role an existing one (e.g. Entscheider) stays
                        if ($role !== null) {
                            $exists->update(['role' => $role]);
                        }
                    } else {
                        DB::table('module_user')->insert(['user_id' => $userId, 'module' => $key, 'role' => $role]);
                    }
                }
            }
        });

        $names = collect($keys)->map(fn ($key) => '„'.(app(ModuleRegistry::class)->get($key)['name'] ?? $key).'“')->implode(', ');
        Notification::make()
            ->title((count($keys) === 1 ? 'Modul ' : 'Module ').$names.' für '.count($userIds).' Kameraden freigeschaltet')
            ->success()
            ->send();
    }

    /** @param  iterable<int>  $userIds */
    public static function revokeModule(iterable $userIds, string $key): void
    {
        $count = DB::table('module_user')
            ->whereIn('user_id', collect($userIds)->all())
            ->where('module', $key)
            ->delete();

        Notification::make()
            ->title('Modul „'.(app(ModuleRegistry::class)->get($key)['name'] ?? $key).'“ bei '.$count.' Kameraden entzogen')
            ->success()
            ->send();
    }

    /** @return array<int, string> functions with their sub-functions below, ids as keys */
    public static function functionFilterOptions(): array
    {
        $options = [];
        foreach (MemberFunction::topLevel()->with('children')->orderBy('sort_order')->orderBy('name')->get() as $function) {
            $options[$function->id] = $function->children->isNotEmpty() ? $function->name.' (mit Unterfunktionen)' : $function->name;
            foreach ($function->children as $child) {
                $options[$child->id] = $function->name.' › '.$child->name;
            }
        }

        return $options;
    }

    public static function syncModuleAccess(User $user, array $formState): void
    {
        $registry = app(ModuleRegistry::class);

        foreach (array_keys($registry->all()) as $key) {
            $enabled = (bool) ($formState["module_access_{$key}"] ?? false);
            $role = $formState["module_role_{$key}"] ?? null;

            if ($enabled) {
                DB::table('module_user')->updateOrInsert(
                    ['user_id' => $user->id, 'module' => $key],
                    ['role' => $role]
                );
            } else {
                DB::table('module_user')
                    ->where('user_id', $user->id)
                    ->where('module', $key)
                    ->delete();
            }
        }
    }
}
