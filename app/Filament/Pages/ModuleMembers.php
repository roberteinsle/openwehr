<?php

namespace App\Filament\Pages;

use App\Enums\MemberStatus;
use App\Filament\Resources\UserResource;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** "Module verwalten" → click on "x Kameraden": who may use one module, switched per Kamerad or for a selection */
class ModuleMembers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'module/{module}/kameraden';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.manage-modules';

    public string $module;

    public static function canAccess(): bool
    {
        return auth()->user()?->is_admin ?? false;
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'module-members';
    }

    public function mount(string $module): void
    {
        abort_unless(app(ModuleRegistry::class)->get($module) !== null, 404);
        $this->module = $module;
    }

    public function getTitle(): string
    {
        return 'Kameraden: '.$this->definition()['name'];
    }

    public function getSubheading(): ?string
    {
        $module = Module::find($this->module);

        return collect([
            $module?->is_active ? null : 'Das Modul ist ausgeschaltet – freigeschaltete Kameraden sehen es erst, wenn es aktiv ist.',
            $module?->auto_assign ? '„Neue Kameraden automatisch freischalten“ ist an: alle Kameraden haben Zugang, auch ohne Haken.' : null,
            'Admins haben immer Zugang.',
        ])->filter()->implode(' ');
    }

    public function getBreadcrumbs(): array
    {
        return [ManageModules::getUrl() => 'Module verwalten', 'Kameraden'];
    }

    public function table(Table $table): Table
    {
        $key = $this->module;
        $roles = $this->definition()['roles'] ?? [];
        $roleOptions = $roles ? array_combine($roles, array_map('mb_ucfirst', $roles)) : [];
        $access = fn ($query) => $query->from('module_user')->whereColumn('module_user.user_id', 'users.id')->where('module_user.module', $key);
        $registry = app(ModuleRegistry::class);
        $restricted = $registry->isRestricted($key);
        $audience = $this->definition()['audience_label'] ?? null;
        // Outside the audience (e.g. not in the Vorstand): unlocking needs a confirmation
        $outside = fn (User $record) => $restricted && ! $registry->isMeantFor($key, $record);

        $columns = [
            TextColumn::make('name')
                ->label('Name')
                ->searchable()
                ->sortable()
                ->description(fn (User $record) => match (true) {
                    $record->is_admin => 'Admin – hat immer Zugang',
                    $outside($record) => 'Nicht im '.$audience.($record->has_access ? ' – trotzdem freigeschaltet' : ''),
                    default => null,
                }),

            TextColumn::make('email')
                ->label('E-Mail')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('groups.name')
                ->label('Abteilungen')
                ->badge()
                ->separator(', ')
                ->placeholder('–'),

            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (MemberStatus $state) => $state->label())
                ->color(fn (MemberStatus $state) => match ($state) {
                    MemberStatus::Active => 'success',
                    MemberStatus::Inactive => 'warning',
                    MemberStatus::Left => 'danger',
                }),

            ToggleColumn::make('has_access')
                ->label('Freigeschaltet')
                ->sortable()
                // Switching on for someone outside the audience goes through "Trotzdem freischalten"
                ->disabled(fn (User $record) => $outside($record) && ! $record->has_access)
                ->tooltip(fn (User $record) => $outside($record) && ! $record->has_access ? 'Nicht im '.$audience.' – über „Trotzdem freischalten“' : null)
                ->getStateUsing(fn (User $record) => (bool) $record->has_access)
                ->updateStateUsing(function (User $record, bool $state) use ($key) {
                    if ($state) {
                        DB::table('module_user')->insertOrIgnore(['user_id' => $record->id, 'module' => $key]);
                    } else {
                        DB::table('module_user')->where('user_id', $record->id)->where('module', $key)->delete();
                    }

                    return $state;
                }),
        ];

        if ($roles) {
            $columns[] = SelectColumn::make('module_role')
                ->label('Rolle')
                ->options($roleOptions)
                ->placeholder('keine')
                ->getStateUsing(fn (User $record) => $record->module_role)
                // Giving a role also unlocks the module
                ->updateStateUsing(function (User $record, ?string $state) use ($key) {
                    DB::table('module_user')->updateOrInsert(['user_id' => $record->id, 'module' => $key], ['role' => $state ?: null]);

                    return $state;
                });
        }

        return $table
            ->query(User::query()
                ->select('users.*')
                ->selectRaw('exists(select 1 from module_user where module_user.user_id = users.id and module_user.module = ?) as has_access', [$key])
                ->addSelect(['module_role' => DB::table('module_user')->select('role')->whereColumn('module_user.user_id', 'users.id')->where('module_user.module', $key)->limit(1)])
                ->with('groups'))
            ->columns($columns)
            ->defaultSort('name')
            ->filters(array_filter([
                TernaryFilter::make('has_access')
                    ->label('Freigeschaltet')
                    ->placeholder('Alle')
                    ->trueLabel('Nur freigeschaltete')
                    ->falseLabel('Nur nicht freigeschaltete')
                    ->queries(
                        true: fn (Builder $query) => $query->whereExists($access),
                        false: fn (Builder $query) => $query->whereNotExists($access),
                    ),
                $roles ? SelectFilter::make('role')
                    ->label('Rolle')
                    ->options($roleOptions)
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereExists(fn ($q) => $access($q)->where('module_user.role', $data['value']))
                        : $query) : null,
                SelectFilter::make('groups')
                    ->label('Abteilung')
                    ->relationship('groups', 'name', fn ($query) => $query->orderBy('sort_order'))
                    ->multiple()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(MemberStatus::cases())->mapWithKeys(fn (MemberStatus $s) => [$s->value => $s->label()])->all())
                    ->default(MemberStatus::Active->value),
            ]))
            ->recordActions([
                Action::make('grant_outside')
                    ->label('Trotzdem freischalten')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->visible(fn (User $record) => $outside($record) && ! $record->has_access)
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => $record->name.' ist nicht im '.$audience)
                    ->modalDescription('„'.$this->definition()['name'].'“ ist nur für den '.$audience.' (inkl. Unterfunktionen) und Admins gedacht. Trotzdem freischalten?')
                    ->modalSubmitActionLabel('Ja, freischalten')
                    ->action(fn (User $record) => $this->grant(new Collection([$record]), null, true)),
            ])
            ->bulkActions([
                BulkAction::make('grant')
                    ->label('Freischalten')
                    ->icon('heroicon-o-plus-circle')
                    ->schema(array_filter([
                        $roles ? Select::make("role_{$key}")->label('Rolle (optional)')->options($roleOptions) : null,
                        $restricted ? Checkbox::make('confirm_outside')->label('Auch Kameraden freischalten, die nicht im '.$audience.' sind')
                            ->helperText('„'.$this->definition()['name'].'“ ist nur für den '.$audience.' (inkl. Unterfunktionen) und Admins gedacht. Ohne Haken werden nur diese freigeschaltet.') : null,
                    ]))
                    ->action(fn (Collection $records, array $data) => $this->grant($records, $data["role_{$key}"] ?? null, (bool) ($data['confirm_outside'] ?? false)))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('revoke')
                    ->label('Entziehen')
                    ->icon('heroicon-o-minus-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => UserResource::revokeModule($records->pluck('id'), $key))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('Keine Kameraden gefunden');
    }

    private function definition(): array
    {
        return app(ModuleRegistry::class)->get($this->module) ?? ['name' => $this->module, 'roles' => []];
    }

    /**
     * Unlocks the module for the selection; existing roles stay unless a role is chosen. Kameraden outside
     * the module's audience (e.g. not in the Vorstand) only with $confirmed.
     */
    private function grant(Collection $records, ?string $role, bool $confirmed = false): void
    {
        $registry = app(ModuleRegistry::class);
        [$records, $skipped] = $records->partition(fn (User $user) => $confirmed || $registry->isMeantFor($this->module, $user));

        if ($skipped->isNotEmpty()) {
            Notification::make()
                ->title($skipped->count().' nicht freigeschaltet: nicht im '.$this->definition()['audience_label'])
                ->body($skipped->pluck('name')->implode(', ').'. Zum Freischalten den Haken „Auch Kameraden freischalten …“ setzen oder „Trotzdem freischalten“ nutzen.')
                ->warning()->send();
        }

        if ($records->isEmpty()) {
            return;
        }

        foreach ($records as $user) {
            DB::table('module_user')->insertOrIgnore(['user_id' => $user->id, 'module' => $this->module]);

            if (filled($role)) {
                DB::table('module_user')->where('user_id', $user->id)->where('module', $this->module)->update(['role' => $role]);
            }
        }

        Notification::make()->title('„'.$this->definition()['name'].'“ für '.$records->count().' Kameraden freigeschaltet')->success()->send();
    }
}
