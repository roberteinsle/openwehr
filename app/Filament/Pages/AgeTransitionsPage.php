<?php

namespace App\Filament\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Group;
use App\Models\User;
use App\Services\AgeTransitions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * Kameraden → Altersübergänge: the Jugendfeuerwehr growing up. Who may now join the Übungsdienste of the
 * Einsatzabteilung (guest from 16), who reached the Übertrittsalter (18), the current guests and whose birth
 * date is missing. The admin confirms each step (App\Services\AgeTransitions).
 */
class AgeTransitionsPage extends Page
{
    protected static ?string $slug = 'kameraden/altersuebergaenge';

    protected static ?string $navigationLabel = 'Altersübergänge';

    protected static ?string $title = 'Altersübergänge';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationParentItem = 'Kameraden';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.age-transitions';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    /** Only with a Jugendabteilung */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && Group::where('is_youth', true)->exists();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(AgeTransitions::class)->openCount();

        return $count > 0 ? (string) $count : null;
    }

    public function getSubheading(): ?string
    {
        return 'Was in der Jugendabteilung eingestellt ist (Kameraden → Abteilungen → Jugendabteilung): ab welchem Alter Jugendliche bei den Übungsdiensten einer anderen Abteilung mitmachen und wann sie übertreten. Nichts passiert von selbst – du bestätigst jeden Schritt.';
    }

    private function run(callable $step, string $done): void
    {
        try {
            $step(app(AgeTransitions::class), auth()->user());
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }
        Notification::make()->title($done)->success()->send();
    }

    public function addGuest(int $userId, int $groupId): void
    {
        $user = User::findOrFail($userId);
        $group = Group::findOrFail($groupId);
        $this->run(fn ($s, $by) => $s->addGuest($user, $group, $by), $user->name.' übt ab jetzt bei der '.$group->name.' mit.');
    }

    public function removeGuest(int $userId, int $groupId): void
    {
        $user = User::findOrFail($userId);
        $group = Group::findOrFail($groupId);
        $this->run(fn ($s, $by) => $s->removeGuest($user, $group, $by), $user->name.' übt nicht mehr bei der '.$group->name.' mit.');
    }

    public function transfer(int $userId, int $fromId, int $toId): void
    {
        $user = User::findOrFail($userId);
        $from = Group::findOrFail($fromId);
        $to = Group::findOrFail($toId);
        $this->run(fn ($s, $by) => $s->transfer($user, $from, $to, $by), $user->name.' ist in die '.$to->name.' übergetreten.');
    }

    protected function getViewData(): array
    {
        $transitions = app(AgeTransitions::class);

        return [
            'guestCandidates' => $transitions->guestCandidates(),
            'transferCandidates' => $transitions->transferCandidates(),
            'guests' => $transitions->guests(),
            'missing' => $transitions->missingBirthDates(),
            'editUrl' => fn (User $u) => UserResource::getUrl('edit', ['record' => $u]),
        ];
    }
}
