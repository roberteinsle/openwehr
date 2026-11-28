<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\ParentAccess;
use App\Services\ParentOnlyAccount;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::impersonateAction(Actions\Action::make('impersonate')),
            Actions\DeleteAction::make()
                ->label('Löschen')
                ->action(function (User $record, Actions\DeleteAction $action) {
                    if (! UserResource::deleteIfUnreferenced($record)) {
                        UserResource::notifyNotDeletable($record->name);
                        $action->halt();
                    }

                    $action->success();
                }),
            UserResource::transferDeleteAction(Actions\Action::make('transfer_delete')),
            // An account made only so a parent can sign in: becomes the Eltern-Login with the same address
            Actions\Action::make('parent_only')
                ->label('Nur Elternteil')
                ->icon('heroicon-o-user-group')
                ->color('gray')
                ->visible(fn (User $record) => ParentOnlyAccount::candidate($record))
                ->requiresConfirmation()
                ->modalHeading('In Eltern-Zugang umwandeln?')
                ->modalDescription(fn (User $record) => 'Die Adresse '.$record->email.' ist als Erziehungsberechtigte(r) bei '
                    .ParentAccess::childrenOf((string) $record->email)->pluck('name')->implode(', ').' eingetragen. '
                    .'Ist '.$record->name.' kein Mitglied der Wehr, wird das Kamerad-Konto entfernt: Die Anmeldung mit derselben Adresse führt dann in den Eltern-Bereich – ohne Dienstausweis, Stunden, Module und Kameradenlisten. Hängen Daten am Konto, bleibt es als „Ausgetreten“ ohne Adresse stehen.')
                ->modalSubmitActionLabel('Umwandeln')
                ->action(function (User $record) {
                    $by = auth()->user();
                    $deleted = app(ParentOnlyAccount::class)->convert($record, $by instanceof User ? $by : $record);
                    Notification::make()->title('In Eltern-Zugang umgewandelt')
                        ->body($deleted ? 'Das Kamerad-Konto ist entfernt.' : 'Das Konto bleibt als „Ausgetreten“ ohne Adresse stehen, weil Daten daran hängen.')
                        ->success()->send();
                    $this->redirect(UserResource::getUrl('index'));
                }),
        ];
    }

    /** Module access state captured in mutateFormDataBeforeSave for use in afterSave. */
    private array $moduleFormState = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Pull out the virtual module_access_* / module_role_* keys so they
        // don't get passed to User::update(). We'll use them in afterSave().
        $this->moduleFormState = Arr::where($data, fn ($v, $k) => str_starts_with($k, 'module_access_') || str_starts_with($k, 'module_role_'));

        return Arr::except($data, array_keys($this->moduleFormState));
    }

    protected function afterSave(): void
    {
        UserResource::syncModuleAccess($this->record, $this->moduleFormState);

        $record = $this->record->fresh();

        // Protect the last admin: if no admin remains, revert
        if (! $record->is_admin) {
            $adminCount = User::where('is_admin', true)->count();
            if ($adminCount === 0) {
                $this->record->update(['is_admin' => true]);

                Notification::make()
                    ->title('Letzter Administrator')
                    ->body('Der letzte Administrator kann sich das Admin-Recht nicht selbst entziehen.')
                    ->danger()
                    ->send();
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
