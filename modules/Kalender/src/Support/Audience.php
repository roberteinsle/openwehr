<?php

namespace Modules\Kalender\src\Support;

use App\Models\MemberFunction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Who an event or a Terminabfrage is for: members of its Abteilungen, Kameraden with one of its
 * Funktionen (a Funktion includes its sub-functions) and the ones invited by name; everyone with
 * access to the Kalender when none is set. Models need the relations groups, functions, invitees.
 */
class Audience
{
    /** Termine of these types also reach the guests of an Abteilung (Jugendliche who join its Übungsdienste, User::guestGroups) */
    public const GUEST_TYPES = ['uebung'];

    /** The Kameraden with access to the Kalender; with an event type of GUEST_TYPES also the guests of its Abteilungen */
    public static function users(Collection $groupIds, Collection $functionIds, Collection $userIds, ?string $type = null): EloquentCollection
    {
        $guests = $type !== null && in_array($type, self::GUEST_TYPES, true) && $groupIds->isNotEmpty();
        $functions = MemberFunction::idsWithChildren($functionIds->all());
        $everyone = $groupIds->isEmpty() && $functionIds->isEmpty() && $userIds->isEmpty();

        return User::active()->orderBy('name')
            ->when(! $everyone, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $groupIds))
                ->when($guests, fn ($w2) => $w2->orWhereHas('guestGroups', fn ($g) => $g->whereIn('groups.id', $groupIds)))
                ->orWhereHas('functions', fn ($f) => $f->whereIn('member_functions.id', $functions))
                ->orWhereIn('id', $userIds)))
            ->get()
            ->filter(fn (User $u) => $u->canUseModule('kalender'))
            ->values();
    }

    /** Restricts a query of events or polls to those meant for the Kamerad; $events: Übungen of guest Abteilungen too */
    public static function scope(Builder $query, User $user, bool $events = false): Builder
    {
        $guestOf = $events ? $user->guestGroups()->pluck('groups.id') : collect();
        $functions = $user->functions()->pluck('member_functions.id');
        // A Funktion of the target group also covers its sub-functions, so look one level up
        $withParents = $functions->merge(MemberFunction::whereIn('id', $functions)->whereNotNull('parent_id')->pluck('parent_id'))->unique();

        return $query->where(fn ($q) => $q->where(fn ($all) => $all->doesntHave('groups')->doesntHave('functions')->doesntHave('invitees'))
            ->orWhereHas('groups', fn ($g) => $g->whereIn('groups.id', $user->groups()->pluck('groups.id')))
            ->orWhereHas('functions', fn ($f) => $f->whereIn('member_functions.id', $withParents))
            ->orWhereHas('invitees', fn ($i) => $i->where('users.id', $user->id))
            ->when($guestOf->isNotEmpty(), fn ($w) => $w->orWhere(fn ($g) => $g->whereIn('type', self::GUEST_TYPES)
                ->whereHas('groups', fn ($gg) => $gg->whereIn('groups.id', $guestOf)))));
    }
}
