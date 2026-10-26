<?php

namespace Modules\Kalender\src\Policies;

use App\Models\User;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\AttendanceService;

class EventPolicy
{
    public function view(User $user, Event $event): bool
    {
        return Event::visibleTo($user)->whereKey($event->id)->exists();
    }

    public function manage(User $user, ?Event $event = null): bool
    {
        return $user->canManageKalender() && ($event === null || ! $event->isExternal());
    }

    /** Terminverwalter, and whoever closes the Anwesenheit (Wehrführer, Stellvertreter) */
    public function recordAttendance(User $user, Event $event): bool
    {
        return $this->manage($user, $event) || $this->closeAttendance($user, $event);
    }

    public function closeAttendance(User $user, Event $event): bool
    {
        return app(AttendanceService::class)->mayClose($user, $event);
    }
}
