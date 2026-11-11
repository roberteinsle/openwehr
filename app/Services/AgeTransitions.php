<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;
use App\Notifications\GuestGroupNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Jugendfeuerwehr growing up, as a process the admin sees and confirms (Kameraden → Altersübergänge):
 * from guest_from_age (e.g. 16) a Jugendlicher may join the Übungsdienste of the guest Abteilung (e.g. the
 * Einsatzabteilung) – they become a guest there (group_guests): the Kalender invites them to its Übungen and
 * the Übungsdienstplan shows them, everything else stays as in the Jugendfeuerwehr. From transfer_age (e.g. 18)
 * they move over: out of the Jugendabteilung, into the other one. Nothing happens by itself – the birth date
 * may be missing or wrong, and the Wehr decides.
 */
class AgeTransitions
{
    /**
     * Jugendliche old enough to join the Übungsdienste of the guest Abteilung, not yet a guest or member there.
     *
     * @return Collection<int, array{user: User, from: Group, to: Group|null, age: int}>
     */
    public function guestCandidates(): Collection
    {
        return $this->youthGroups()->filter(fn (Group $g) => $g->guest_group_id && $g->guest_from_age)
            ->flatMap(fn (Group $g) => $g->users()->where('is_active', true)->whereNotNull('birth_date')->with(['groups', 'guestGroups'])->orderBy('name')->get()
                ->filter(fn (User $u) => $u->age() >= $g->guest_from_age && ($g->transfer_age === null || $u->age() < $g->transfer_age)
                    && ! $u->groups->contains('id', $g->guest_group_id) && ! $u->guestGroups->contains('id', $g->guest_group_id))
                ->map(fn (User $u) => ['user' => $u, 'from' => $g, 'to' => $g->guestGroup, 'age' => (int) $u->age()]))
            ->values();
    }

    /**
     * Jugendliche at the transfer age, still in the Jugendabteilung.
     *
     * @return Collection<int, array{user: User, from: Group, to: Group|null, age: int}>
     */
    public function transferCandidates(): Collection
    {
        return $this->youthGroups()->filter(fn (Group $g) => $g->transfer_group_id && $g->transfer_age)
            ->flatMap(fn (Group $g) => $g->users()->where('is_active', true)->whereNotNull('birth_date')->orderBy('name')->get()
                ->filter(fn (User $u) => $u->age() >= $g->transfer_age)
                ->map(fn (User $u) => ['user' => $u, 'from' => $g, 'to' => $g->transferGroup, 'age' => (int) $u->age()]))
            ->values();
    }

    /** @return Collection<int, array{user: User, group: Group}> current guests, by Abteilung */
    public function guests(): Collection
    {
        return Group::with(['guests' => fn ($q) => $q->orderBy('name')])->orderBy('sort_order')->get()
            ->flatMap(fn (Group $g) => $g->guests->map(fn (User $u) => ['user' => $u, 'group' => $g]))->values();
    }

    /** @return Collection<int, User> members of a Jugendabteilung without a birth date – their age is unknown */
    public function missingBirthDates(): Collection
    {
        return User::active()->whereNull('birth_date')->whereHas('groups', fn ($q) => $q->where('is_youth', true))->orderBy('name')->get();
    }

    /** What the menu badge counts: decisions waiting */
    public function openCount(): int
    {
        return $this->guestCandidates()->count() + $this->transferCandidates()->count();
    }

    public function addGuest(User $user, Group $group, User $by): void
    {
        $this->assertAdmin($by);
        if ($user->groups()->whereKey($group->id)->exists()) {
            throw ValidationException::withMessages(['guest' => $user->name.' ist schon Mitglied der '.$group->name.'.']);
        }
        $user->guestGroups()->syncWithoutDetaching([$group->id => ['added_by' => $by->id]]);
        app(ActivityRecorder::class)->record('updated', User::class, $user->id, $user->name.': übt mit bei '.$group->name);

        try {
            $user->notify(new GuestGroupNotification($group));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function removeGuest(User $user, Group $group, User $by): void
    {
        $this->assertAdmin($by);
        $user->guestGroups()->detach($group->id);
        app(ActivityRecorder::class)->record('updated', User::class, $user->id, $user->name.': übt nicht mehr mit bei '.$group->name);
    }

    /** Übertritt: out of the Jugendabteilung, into the other Abteilung; a guest place there ends */
    public function transfer(User $user, Group $from, Group $to, User $by): void
    {
        $this->assertAdmin($by);
        DB::transaction(function () use ($user, $from, $to) {
            $user->groups()->detach($from->id);
            $user->groups()->syncWithoutDetaching([$to->id]);
            $user->guestGroups()->detach($to->id);
        });
        // Saved, so whatever follows the member data (e.g. the Diensthistorie of the Personalverwaltung) sees it
        $user->touch();
        app(ActivityRecorder::class)->record('updated', User::class, $user->id, $user->name.': Übertritt von '.$from->name.' in '.$to->name);
    }

    /** @return Collection<int, Group> */
    private function youthGroups(): Collection
    {
        return Group::where('is_youth', true)->with(['guestGroup', 'transferGroup'])->orderBy('sort_order')->get();
    }

    private function assertAdmin(User $by): void
    {
        if (! $by->is_admin) {
            throw ValidationException::withMessages(['guest' => 'Das dürfen nur Admins.']);
        }
    }
}
