<?php

namespace Modules\Kalender\src\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Response;
use Modules\Kalender\src\Services\EventService;

/**
 * The Rückmeldungen of a Kamerad for the start page (and of a child for its parents): the coming
 * Termine with Rückmeldung that are meant for them, open ones and the answer given so far.
 */
final class Rsvp
{
    /** How far ahead the start page looks */
    public const DAYS = 30;

    /** @return Collection<int, array{event: Event, response: ?Response}> in order of time */
    public static function forUser(User $user): Collection
    {
        $events = Event::visibleTo($user)->where('rsvp', true)->whereNull('cancelled_at')
            ->where('starts_at', '>', now())->where('starts_at', '<', now()->addDays(self::DAYS))
            ->with(['groups', 'functions', 'responses' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderBy('starts_at')->get();
        $service = app(EventService::class);

        return $events
            ->filter(fn (Event $e) => $e->responses->isNotEmpty() || $e->created_by === $user->id || $service->audience($e)->contains('id', $user->id))
            ->map(fn (Event $e) => ['event' => $e, 'response' => $e->responses->first()])
            ->values();
    }

    /** @param  Collection<int, array{event: Event, response: ?Response}>  $rows */
    public static function open(Collection $rows): Collection
    {
        return $rows->filter(fn ($row) => $row['response']?->status === null)->values();
    }

    public static function openCount(User $user): int
    {
        return self::open(self::forUser($user))->count();
    }
}
