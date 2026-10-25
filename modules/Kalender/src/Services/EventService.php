<?php

namespace Modules\Kalender\src\Services;

use App\Models\Guardian;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Response;
use Modules\Kalender\src\Notifications\EventCancelledNotification;
use Modules\Kalender\src\Notifications\EventChangedNotification;
use Modules\Kalender\src\Notifications\EventCreatedNotification;
use Modules\Kalender\src\Notifications\GuardianCopyNotification;
use Modules\Kalender\src\Notifications\RsvpReminderNotification;
use Modules\Kalender\src\Support\Audience;
use Throwable;

/** Creating, changing and cancelling events, repetitions, audience and Rückmeldungen */
class EventService
{
    public const MAX_OCCURRENCES = 104;

    public const REPEATS = ['weekly' => 'Jede Woche', 'biweekly' => 'Alle 2 Wochen', 'monthly' => 'Jeden Monat'];

    /**
     * @param  array{title: string, type: string, starts_at: Carbon, ends_at: Carbon, all_day?: bool, location?: ?string, description?: ?string, rsvp?: bool, rsvp_deadline?: ?Carbon}  $data
     * @param  int[]  $groupIds  audience; empty = everyone
     * @param  array{freq: string, until: Carbon}|null  $repeat
     * @param  int[]  $userIds  Kameraden invited individually
     * @param  int[]  $functionIds  Funktionen of the target group
     * @return Collection<int, Event> the created events in order
     */
    public function create(array $data, array $groupIds, ?User $by, ?array $repeat = null, bool $notify = false, array $userIds = [], array $functionIds = []): Collection
    {
        $this->assertTimes($data['starts_at'], $data['ends_at']);
        $occurrences = $this->occurrences($data['starts_at'], $repeat);
        $seriesId = count($occurrences) > 1 ? (string) Str::uuid() : null;
        $duration = $data['starts_at']->diffInSeconds($data['ends_at']);
        $deadlineBefore = isset($data['rsvp_deadline']) ? $data['rsvp_deadline']->diffInSeconds($data['starts_at'], false) : null;

        $events = DB::transaction(function () use ($data, $groupIds, $userIds, $functionIds, $by, $occurrences, $seriesId, $duration, $deadlineBefore) {
            return collect($occurrences)->map(function (Carbon $start) use ($data, $groupIds, $userIds, $functionIds, $by, $seriesId, $duration, $deadlineBefore) {
                $event = Event::create([
                    ...$data,
                    'starts_at' => $start->copy()->utc(),
                    'ends_at' => $start->copy()->addSeconds($duration)->utc(),
                    // The deadline keeps its distance to the start in every repetition
                    'rsvp_deadline' => $deadlineBefore !== null ? $start->copy()->subSeconds($deadlineBefore)->utc() : null,
                    'series_id' => $seriesId,
                    'created_by' => $by?->id,
                ]);
                $event->groups()->sync($groupIds);
                $event->invitees()->sync($userIds);
                $event->functions()->sync($functionIds);
                // Who puts the Termin in takes part: already zugesagt (can be changed like any answer)
                if (($data['rsvp'] ?? false) && $by) {
                    Response::create(['event_id' => $event->id, 'user_id' => $by->id, 'status' => 'yes', 'set_by' => $by->id]);
                }

                return $event;
            });
        });

        if ($notify) {
            $this->announce($events->first(), $by, $events->count());
        }

        return $events;
    }

    /** Mails the target group about a new event (or series) */
    public function announce(Event $event, ?User $by, int $count = 1): void
    {
        $this->notify($this->audience($event)->reject(fn (User $u) => $by && $u->is($by)), new EventCreatedNotification($event, $count), $event);
    }

    /**
     * Saves changes to one event. With $following, title, type, place, description, Rückmeldung and
     * audience also go to the later events of the same repetition; times only change for this one.
     */
    public function update(Event $event, array $data, array $groupIds, bool $following = false, array $userIds = [], ?array $functionIds = null, ?User $by = null, bool $notify = false): void
    {
        $this->assertTimes($data['starts_at'], $data['ends_at']);
        // Callers that don't know Funktionen keep the ones set
        $functionIds ??= $event->functions()->pluck('member_functions.id')->all();
        [$oldTime, $oldLocation] = [$event->timeLabel(), (string) $event->location];

        DB::transaction(function () use ($event, $data, $groupIds, $userIds, $functionIds, $following) {
            $event->update([...$data, 'starts_at' => $data['starts_at']->copy()->utc(), 'ends_at' => $data['ends_at']->copy()->utc()]);
            $event->groups()->sync($groupIds);
            $event->invitees()->sync($userIds);
            $event->functions()->sync($functionIds);

            if ($following && $event->series_id) {
                $shared = collect($data)->only(['title', 'type', 'location', 'place_id', 'dress_code_ids', 'description', 'rsvp', 'min_participants', 'is_public'])->all();
                Event::where('series_id', $event->series_id)->where('starts_at', '>', $event->starts_at)->get()
                    ->each(function (Event $later) use ($shared, $groupIds, $userIds, $functionIds) {
                        $later->update($shared);
                        $later->groups()->sync($groupIds);
                        $later->invitees()->sync($userIds);
                        $later->functions()->sync($functionIds);
                    });
            }
        });

        $this->tellChange($event->refresh(), $oldTime, $oldLocation, $by, $notify);
    }

    /**
     * A new time or place goes to everyone who said yes or maybe; with "per E-Mail informieren" every
     * change goes to the whole target group. Past and cancelled events stay quiet.
     */
    private function tellChange(Event $event, string $oldTime, string $oldLocation, ?User $by, bool $everyone): void
    {
        $moved = $oldTime !== $event->timeLabel() || $oldLocation !== (string) $event->location;
        if ($event->isCancelled() || $event->ends_at->isPast() || (! $moved && ! $everyone)) {
            return;
        }

        $people = $everyone ? $this->audience($event) : $this->committed($event);
        $this->notify($people->reject(fn (User $u) => $by && $u->is($by)), new EventChangedNotification($event, $oldTime, $oldLocation), $event);
    }

    /** Who said yes or maybe and is still invited */
    private function committed(Event $event): Collection
    {
        $audience = $this->audience($event)->pluck('id');

        return $event->responses()->whereIn('status', ['yes', 'maybe'])->with('user')->get()
            ->pluck('user')->filter()->filter(fn (User $u) => $audience->contains($u->id))->values();
    }

    /** The event stays visible as "abgesagt"; everyone who said yes or maybe is told */
    public function cancel(Event $event, ?User $by): void
    {
        $event->update(['cancelled_at' => now()]);

        $affected = $event->responses()->whereIn('status', ['yes', 'maybe'])->with('user')->get()
            ->pluck('user')->filter()->reject(fn (User $u) => $by && $u->is($by));
        $this->notify($affected, new EventCancelledNotification($event), $event);
    }

    /** Takes place after all: everyone who had said yes or maybe hears it */
    public function reinstate(Event $event, ?User $by = null): void
    {
        $event->update(['cancelled_at' => null]);

        if (! $event->ends_at->isPast()) {
            $this->notify($this->committed($event)->reject(fn (User $u) => $by && $u->is($by)), new EventChangedNotification($event, reinstated: true), $event);
        }
    }

    /** Deletes the event, or with $following also the later ones of the repetition */
    public function delete(Event $event, bool $following = false): void
    {
        DB::transaction(function () use ($event, $following) {
            if ($following && $event->series_id) {
                Event::where('series_id', $event->series_id)->where('starts_at', '>', $event->starts_at)->delete();
            }

            $event->delete();
        });
    }

    public function respond(Event $event, User $user, string $status, ?string $comment = null): Response
    {
        if (! array_key_exists($status, Response::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Ungültige Rückmeldung.']);
        }

        if (! $event->acceptsResponses()) {
            throw ValidationException::withMessages(['status' => 'Für diesen Termin ist keine Rückmeldung (mehr) möglich.']);
        }

        if (! $this->mayRespond($event, $user)) {
            throw ValidationException::withMessages(['status' => 'Dieser Termin ist nicht für dich.']);
        }

        return Response::updateOrCreate(['event_id' => $event->id, 'user_id' => $user->id], [
            'status' => $status,
            'comment' => filled($comment) ? trim($comment) : null,
        ]);
    }

    /** The target group, who created the Termin and who is already on it (e.g. responsible for an Übungsdienst) answer */
    public function mayRespond(Event $event, User $user): bool
    {
        return $event->created_by === $user->id || $event->responses()->where('user_id', $user->id)->exists()
            || $this->audience($event)->contains('id', $user->id);
    }

    /** A Terminverwalter answers for someone (e.g. after a phone call), also after the deadline; null clears it */
    public function respondFor(Event $event, User $user, ?string $status, User $by): void
    {
        if ($status !== null && ! array_key_exists($status, Response::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Ungültige Rückmeldung.']);
        }

        Response::updateOrCreate(['event_id' => $event->id, 'user_id' => $user->id], ['status' => $status, 'set_by' => $by->id]);
    }

    /** Who was actually there; also for Kameraden outside the audience who came along */
    public function setAttendance(Event $event, User $user, ?bool $attended, User $by): void
    {
        if (! $event->hasStarted()) {
            throw ValidationException::withMessages(['attended' => 'Die Anwesenheit lässt sich erst ab Beginn des Termins erfassen.']);
        }

        Response::updateOrCreate(['event_id' => $event->id, 'user_id' => $user->id], ['attended' => $attended, 'set_by' => $by->id]);
    }

    /**
     * Active Kameraden with access to the module: the members of the event's Abteilungen, those
     * with one of its Funktionen and the individually invited ones; everyone if none is set.
     */
    public function audience(Event $event): EloquentCollection
    {
        return Audience::users($event->groups()->pluck('groups.id'), $event->functions()->pluck('member_functions.id'), $event->invitees()->pluck('users.id'));
    }

    /** Rückmeldungen grouped, who of the audience has not answered yet, attendance and whether there are enough */
    public function summary(Event $event): array
    {
        $responses = $event->responses()->with('user')->get();
        $answered = $responses->whereNotNull('status')->pluck('user_id')->all();
        $yes = $responses->where('status', 'yes')->values();

        return [
            'yes' => $yes,
            'maybe' => $responses->where('status', 'maybe')->values(),
            'no' => $responses->where('status', 'no')->values(),
            'open' => $this->audience($event)->reject(fn (User $u) => in_array($u->id, $answered, true))->values(),
            'attended' => $responses->where('attended', true)->values(),
            'missing' => $event->min_participants ? max(0, $event->min_participants - $yes->count()) : 0,
        ];
    }

    /**
     * Reminds everyone who has not answered: two days before the deadline, or three days before the
     * start if there is none. Each event is claimed with an atomic update, so it happens once.
     */
    public function remindPending(): int
    {
        $sent = 0;

        $candidates = Event::where('rsvp', true)->whereNull('cancelled_at')->whereNull('reminder_sent_at')->whereNull('source_id')
            ->where('starts_at', '>', now())
            ->where(fn ($q) => $q
                ->where(fn ($d) => $d->whereNotNull('rsvp_deadline')->where('rsvp_deadline', '>', now())->where('rsvp_deadline', '<=', now()->addDays(2)))
                ->orWhere(fn ($d) => $d->whereNull('rsvp_deadline')->where('starts_at', '<=', now()->addDays(3))))
            ->pluck('id');

        foreach ($candidates as $id) {
            if (DB::table('kal_events')->where('id', $id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now()]) !== 1) {
                continue;
            }

            $event = Event::find($id);
            $open = $this->summary($event)['open'];
            $this->notify($open, new RsvpReminderNotification($event), $event);
            $sent += $open->count();
        }

        return $sent;
    }

    /**
     * Everyone of the target group and everyone who answered, sorted by name, for CSV and print.
     *
     * @return Collection<int, array{user: User, groups: string, status: string, comment: string, attended: string}>
     */
    public function participantRows(Event $event): Collection
    {
        $responses = $event->responses()->get()->keyBy('user_id');

        return $this->audience($event)->concat(User::whereIn('id', $responses->keys())->get())->unique('id')->sortBy('name')->values()
            ->map(function (User $user) use ($responses) {
                $response = $responses->get($user->id);

                return [
                    'user' => $user,
                    'groups' => $user->groups()->pluck('name')->implode(', '),
                    'status' => $response?->status ? $response->label() : 'offen',
                    'comment' => $response?->comment ?? '',
                    'attended' => match ($response?->attended) {
                        true => 'ja', false => 'nein', default => ''
                    },
                ];
            });
    }

    /** Participant list for the Terminverwalter, as CSV for Excel */
    public function participantsCsv(Event $event): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        Csv::put($out, ['Name', 'Abteilungen', 'Rückmeldung', 'Kommentar', 'Anwesend'], ';');
        foreach ($this->participantRows($event) as $row) {
            Csv::put($out, [$row['user']->name, $row['groups'], $row['status'], $row['comment'], $row['attended']], ';');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /** @return Carbon[] start times of all occurrences (Berlin wall clock, so 19:00 stays 19:00 across DST) */
    public function occurrences(Carbon $start, ?array $repeat): array
    {
        $first = $start->copy()->setTimezone('Europe/Berlin');

        if (! $repeat || empty($repeat['freq'])) {
            return [$first];
        }

        $until = $repeat['until']->copy()->setTimezone('Europe/Berlin')->endOfDay();
        $result = [];

        for ($i = 0; $i < self::MAX_OCCURRENCES; $i++) {
            $next = match ($repeat['freq']) {
                'weekly' => $first->copy()->addWeeks($i),
                'biweekly' => $first->copy()->addWeeks(2 * $i),
                'monthly' => $first->copy()->addMonthsNoOverflow($i),
                default => throw ValidationException::withMessages(['repeat' => 'Unbekannte Wiederholung.']),
            };

            if ($next->gt($until)) {
                break;
            }

            $result[] = $next;
        }

        return $result;
    }

    private function assertTimes(Carbon $start, Carbon $end): void
    {
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Das Ende muss nach dem Beginn liegen.']);
        }
    }

    /** To each Kamerad, and a copy to their Erziehungsberechtigte (they answer for the child by link) */
    private function notify(Collection $users, Notification $notification, ?Event $event = null): void
    {
        $users->each(function (User $user) use ($notification, $event) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
            if ($event) {
                $user->guardians->each(function (Guardian $guardian) use ($notification, $user, $event) {
                    try {
                        $guardian->notify(new GuardianCopyNotification($notification, $user, $event));
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
            }
        });
    }

    /** An Erziehungsberechtigte(r) answers for the child; the comment says who */
    public function respondAsGuardian(Event $event, Guardian $guardian, string $status, ?string $comment = null): Response
    {
        $note = trim((string) $comment);

        return $this->respond($event, $guardian->user, $status, mb_substr(($note !== '' ? $note.' ' : '').'(Rückmeldung von '.$guardian->name.')', 0, 500));
    }
}
