<?php

namespace Modules\Kalender\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\AttendanceService;
use Modules\Kalender\src\Services\EventService;

/** One event: details, own Rückmeldung, overview of all Rückmeldungen, and cancel/delete for Terminverwalter */
class EventDetail extends Component
{
    public Event $event;

    public string $comment = '';

    public function mount(): void
    {
        $this->comment = (string) $this->event->responses()->where('user_id', auth()->id())->value('comment');
    }

    public function respond(string $status, EventService $events): void
    {
        try {
            $events->respond($this->event, auth()->user(), $status, $this->comment);
        } catch (ValidationException $e) {
            $this->addError('status', collect($e->errors())->flatten()->first());
        }
    }

    /** Terminverwalter: answer for someone ('' clears the answer) */
    public function respondFor(int $userId, string $status, EventService $events): void
    {
        $this->authorize('manage', $this->event);
        $events->respondFor($this->event, User::findOrFail($userId), $status !== '' ? $status : null, auth()->user());
    }

    /** Terminverwalter, Wehrführer: who was there */
    public function setAttendance(int $userId, bool $attended, EventService $events): void
    {
        $this->authorize('recordAttendance', $this->event);

        try {
            $events->setAttendance($this->event, User::findOrFail($userId), $attended, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('attended', collect($e->errors())->flatten()->first());
        }
    }

    /** Wehrführer: send the list to the Wehrführung now */
    public function closeAttendance(AttendanceService $attendance): void
    {
        $this->authorize('closeAttendance', $this->event);
        try {
            $attendance->close($this->event, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('attendance', collect($e->errors())->flatten()->first());
        }
        $this->event->refresh();
    }

    public function sendAttendanceCorrection(AttendanceService $attendance): void
    {
        $this->authorize('closeAttendance', $this->event);
        try {
            $attendance->sendCorrection($this->event, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('attendance', collect($e->errors())->flatten()->first());
        }
        $this->event->refresh();
    }

    public function cancelEvent(EventService $events): void
    {
        $this->authorize('manage', $this->event);
        $events->cancel($this->event, auth()->user());
        $this->event->refresh();
    }

    public function reinstate(EventService $events): void
    {
        $this->authorize('manage', $this->event);
        $events->reinstate($this->event, auth()->user());
        $this->event->refresh();
    }

    public function deleteEvent(bool $following, EventService $events)
    {
        $this->authorize('manage', $this->event);
        $events->delete($this->event, $following);

        return redirect()->route('kalender.index');
    }

    public function render(EventService $events, AttendanceService $attendance)
    {
        $user = auth()->user();

        $manager = $user->can('manage', $this->event);
        $recorder = $user->can('recordAttendance', $this->event);
        $responses = $this->event->responses()->get()->keyBy('user_id');

        return view('kalender::livewire.event-detail', [
            'mine' => $responses->get($user->id),
            // Everyone the event is for, plus whoever was recorded without being in the audience
            'people' => $recorder
                ? $events->audience($this->event)->concat(User::whereIn('id', $responses->keys())->get())->unique('id')->sortBy('name')->values()
                : collect(),
            'responses' => $responses,
            'summary' => $this->event->rsvp ? $events->summary($this->event) : null,
            'manager' => $manager,
            'recorder' => $recorder,
            'attendance' => $attendance->appliesTo($this->event) && $this->event->hasStarted() && $recorder ? [
                'mayClose' => $user->can('closeAttendance', $this->event),
                'recipients' => $attendance->recipientLabel(),
            ] : null,
            'isAudience' => $events->mayRespond($this->event, $user),
        ]);
    }
}
