<?php

namespace Modules\Lehrgaenge\src\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Deletion;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Notifications\CourseConfirmedNotification;
use Modules\Lehrgaenge\src\Notifications\CourseReminderNotification;
use Modules\Lehrgaenge\src\Notifications\RefreshDigestNotification;
use Modules\Lehrgaenge\src\Notifications\RefreshDueNotification;
use Modules\Lehrgaenge\src\Notifications\RegisteredByManagerNotification;
use Modules\Lehrgaenge\src\Notifications\RegistrationCancelledNotification;
use Modules\Lehrgaenge\src\Notifications\RegistrationRejectedNotification;
use Modules\Lehrgaenge\src\Notifications\RequestReceivedNotification;
use Modules\Lehrgaenge\src\Notifications\WaitlistedNotification;
use Throwable;

/**
 * The Lehrgang process, and the only place that changes it:
 * 1. the Kamerad reports Interesse (the Ausbildungsleiter are told),
 * 2. the Ausbildungsleiter registers the Kamerad by hand in fox112 (Warteliste, the Kamerad is told)
 *    and, once confirmed, enters the days with time and place (the Kamerad is told),
 * 3. 14 days before the first day the Kamerad is reminded,
 * 4. the Kamerad can cancel with a reason (the Ausbildungsleiter are told, sign off in fox112 and mark it done),
 * 5. after the Lehrgang the Ausbildungsleiter sets it absolviert.
 * Admin and Ausbildungsleiter can also decline a registration with a comment.
 */
class RegistrationService
{
    public const REMIND_DAYS = 14;

    /** Today's date in Berlin, at midnight like the stored dates */
    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    public function request(User $user, Course $course, ?string $note): Record
    {
        if (! $course->isOffered()) {
            throw ValidationException::withMessages(['course' => 'Dieser Lehrgang wird nicht mehr angeboten.']);
        }

        if (Record::where('user_id', $user->id)->where('course_id', $course->id)->whereIn('status', Record::OPEN)->exists()) {
            throw ValidationException::withMessages(['course' => 'Für diesen Lehrgang hast du schon Interesse gemeldet.']);
        }

        if (Record::where('user_id', $user->id)->where('course_id', $course->id)->where('status', 'completed')->exists()) {
            throw ValidationException::withMessages(['course' => 'Diesen Lehrgang hast du schon absolviert.']);
        }

        $record = Record::create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'requested', 'notes' => filled($note) ? trim($note) : null]);
        $this->notify($this->managers()->reject(fn (User $m) => $m->is($user)), new RequestReceivedNotification($record));

        return $record;
    }

    /**
     * The Ausbildungsleiter (or an admin) puts Kameraden down for a Lehrgang themselves, e.g. after
     * a Dienstbesprechung: like their own "Interesse melden", then the usual flow (fox112, Termine,
     * absolviert). Kameraden with a running registration or the Lehrgang absolviert are skipped.
     * The Kamerad is told by mail and under the bell.
     *
     * @param  list<int>  $memberIds
     * @return array{registered: list<string>, skipped: list<string>} names
     */
    public function registerFor(User $by, Course $course, array $memberIds, ?string $note = null): array
    {
        if (! $by->canManageLehrgaenge()) {
            throw ValidationException::withMessages(['register' => 'Anmelden können die Ausbildungsleiter.']);
        }
        if (! $course->isOffered()) {
            throw ValidationException::withMessages(['register' => 'Dieser Lehrgang wird nicht angeboten.']);
        }
        $members = User::active()->whereIn('id', array_map('intval', $memberIds))->orderBy('name')->get();
        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['register' => 'Bitte mindestens einen aktiven Kameraden wählen.']);
        }
        $note = filled($note) ? mb_substr(trim($note), 0, 1000) : null;
        $result = ['registered' => [], 'skipped' => []];
        foreach ($members as $member) {
            $busy = Record::where('user_id', $member->id)->where('course_id', $course->id)->whereIn('status', [...Record::OPEN, 'completed'])->exists();
            if ($busy) {
                $result['skipped'][] = $member->name;

                continue;
            }
            $record = Record::create(['user_id' => $member->id, 'course_id' => $course->id, 'status' => 'requested',
                'notes' => trim('Angemeldet von '.$by->name.($note ? ': '.$note : ''))]);
            $result['registered'][] = $member->name;
            if (! $member->is($by)) {
                $this->notify(collect([$member]), new RegisteredByManagerNotification($record->load('course'), $by->name));
            }
        }

        return $result;
    }

    /**
     * fox112 has confirmed: the days with time and place go in and the Kamerad gets them by mail.
     * On a confirmed Lehrgang it corrects the days and says so.
     *
     * @param  list<array{date: string, starts_at?: ?string, ends_at?: ?string, location?: ?string}>  $sessions  date as Y-m-d, times as H:i
     */
    public function confirm(Record $record, array $sessions): void
    {
        $this->assertStatus($record, Record::OPEN);
        $sessions = $this->validSessions($sessions);
        $changed = $record->status === 'confirmed';
        $first = Carbon::parse($sessions[0]['date']);

        DB::transaction(function () use ($record, $sessions, $first) {
            $record->sessions()->delete();
            $record->sessions()->createMany($sessions);
            $record->update([
                'status' => 'confirmed',
                'starts_on' => $first->toDateString(),
                'confirmed_at' => $record->confirmed_at ?? now(),
                // Within the reminder window the confirmation is the reminder
                'start_reminded_at' => $first->lte(self::today()->addDays(self::REMIND_DAYS)) ? now() : null,
            ]);
        });

        $this->notify(collect([$record->user]), new CourseConfirmedNotification($record->fresh(['course', 'sessions']), $changed));
    }

    /**
     * The Ausbildungsleiter has registered the Interesse in fox112: the Kamerad is on the Warteliste and
     * no longer counts as open Interesse. $on = false takes it back (e.g. clicked by mistake).
     */
    public function waitlist(Record $record, bool $on = true): void
    {
        $this->assertStatus($record, ['requested']);
        if ($on === ($record->waitlisted_at !== null)) {
            return;
        }
        $record->update(['waitlisted_at' => $on ? now() : null]);
        if ($on) {
            $this->notify(collect([$record->user]), new WaitlistedNotification($record->fresh(['course', 'user'])));
        }
    }

    /** The Ausbildungsleiter has signed an Absage off in fox112: it no longer shows red on the start page */
    public function markCancelSeen(Record $record, bool $seen = true): void
    {
        $this->assertStatus($record, ['cancelled']);
        $record->update(['cancel_seen_at' => $seen ? now() : null]);
    }

    /** @return Builder<Record> Absagen of the last 90 days the Ausbildungsleiter still have to sign off in fox112 */
    public static function unseenCancellations(): Builder
    {
        return Record::where('status', 'cancelled')->whereNull('cancel_seen_at')->where('cancelled_at', '>=', now()->subDays(90));
    }

    /** The Kamerad cancels; the reason is kept and the Ausbildungsleiter are told */
    public function cancel(Record $record, string $reason): void
    {
        $this->assertStatus($record, Record::OPEN);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Bitte einen Grund angeben.']);
        }

        $record->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => trim($reason)]);
        $this->notify($this->managers()->reject(fn (User $m) => $m->is($record->user)), new RegistrationCancelledNotification($record->fresh(['course', 'user', 'sessions'])));
    }

    /** Admin or Ausbildungsleiter decline a registration with a comment; the Kamerad gets it by mail */
    public function reject(Record $record, string $comment): void
    {
        $this->assertStatus($record, Record::OPEN);

        if (trim($comment) === '') {
            throw ValidationException::withMessages(['comment' => 'Bitte einen Kommentar für den Kameraden angeben.']);
        }

        $record->update(['status' => 'rejected', 'cancelled_at' => now(), 'cancel_reason' => trim($comment)]);
        $this->notify(collect([$record->user]), new RegistrationRejectedNotification($record->fresh(['course', 'user'])));
    }

    /** Absolviert, on the last day of the Lehrgang unless given */
    public function complete(Record $record, ?string $completedOn = null): void
    {
        $this->assertStatus($record, ['confirmed']);
        $record->update(['status' => 'completed', 'completed_on' => $completedOn ?? $record->sessions()->max('date')]);
    }

    /**
     * Ausbildungsleiter: a Lehrgang absolviert outside the app (before it, in another Kreis, at the
     * Landesfeuerwehrschule). A Lehrgang not in the list is created by name; a running registration
     * for it is completed instead of adding a second record.
     */
    public function recordCompleted(User $member, string $courseName, ?string $completedOn, ?string $note = null): Record
    {
        [$course, $date] = $this->completedEntry($courseName, $completedOn);
        $done = Record::where('user_id', $member->id)->where('course_id', $course->id)->where('status', 'completed')->first();
        if ($done) {
            // A Lehrgang with a refresher interval: a later date is the Auffrischung
            if ($course->repeat_months && $date && (! $done->completed_on || $date->gt($done->completed_on))) {
                $done->update(['completed_on' => $date, 'refresh_reminded_on' => null]);

                return $done;
            }
            throw ValidationException::withMessages(['entry_course' => '„'.$course->name.'“ ist bei '.$member->name.' schon als absolviert eingetragen.']);
        }

        $note = filled($note) ? mb_substr(trim($note), 0, 500) : null;
        $open = Record::where('user_id', $member->id)->where('course_id', $course->id)->whereIn('status', Record::OPEN)->first();
        if ($open) {
            $open->update(['status' => 'completed', 'completed_on' => $date]);

            return $open;
        }

        return Record::create([
            'user_id' => $member->id, 'course_id' => $course->id, 'status' => 'completed',
            'completed_on' => $date, 'notes' => $note, 'entered_manually' => true,
        ]);
    }

    /** Admin or Ausbildungsleiter correct an absolvierter Lehrgang: the Lehrgang (also a new name), the date, the note */
    public function updateCompleted(Record $record, string $courseName, ?string $completedOn, ?string $note = null): Record
    {
        $this->assertStatus($record, ['completed']);
        [$course, $date] = $this->completedEntry($courseName, $completedOn);
        if ($course->id !== $record->course_id
            && Record::where('user_id', $record->user_id)->where('course_id', $course->id)->where('status', 'completed')->exists()) {
            throw ValidationException::withMessages(['entry_course' => '„'.$course->name.'“ ist bei '.$record->user->name.' schon als absolviert eingetragen.']);
        }

        $record->update(['course_id' => $course->id, 'completed_on' => $date, 'notes' => filled($note) ? mb_substr(trim($note), 0, 500) : null]);

        return $record->load('course');
    }

    /**
     * Deletes an absolvierter Lehrgang. With a reason it stays documented (what, when, why, by whom –
     * shown with the Kamerad's Lehrgänge); without one it is simply gone (the activity log still has it).
     */
    public function deleteCompleted(Record $record, User $by, ?string $reason = null): void
    {
        $this->assertStatus($record, ['completed']);
        DB::transaction(function () use ($record, $by, $reason) {
            if (filled($reason)) {
                Deletion::create([
                    'user_id' => $record->user_id, 'course_name' => $record->course?->name ?? 'Lehrgang', 'completed_on' => $record->completed_on,
                    'reason' => mb_substr(trim($reason), 0, 1000), 'deleted_by' => $by->id,
                ]);
            }
            $record->delete();
        });
    }

    /**
     * Ausbildungsleiter: one Lehrgang absolviert by many Kameraden at once, e.g. when the old Lehrgänge
     * go in for the first time. The date is optional (null = not known); Kameraden who already have it
     * are skipped.
     *
     * @param  list<int>  $memberIds
     * @return array{added: list<string>, skipped: list<string>} names
     */
    public function recordCompletedMany(User $by, string $courseName, array $memberIds, ?string $completedOn = null, ?string $note = null): array
    {
        if (! $by->canManageLehrgaenge()) {
            throw ValidationException::withMessages(['entry_course' => 'Nachtragen können die Ausbildungsleiter.']);
        }
        $this->completedEntry($courseName, $completedOn);
        $members = User::whereIn('id', array_map('intval', $memberIds))->orderBy('name')->get();
        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['entry_members' => 'Bitte mindestens einen Kameraden wählen.']);
        }
        $result = ['added' => [], 'skipped' => []];
        DB::transaction(function () use ($members, $courseName, $completedOn, $note, &$result) {
            foreach ($members as $member) {
                try {
                    $this->recordCompleted($member, $courseName, $completedOn, $note);
                    $result['added'][] = $member->name;
                } catch (ValidationException) {
                    $result['skipped'][] = $member->name;
                }
            }
        });

        return $result;
    }

    /** @return array{0: Course, 1: ?Carbon} the Lehrgang (created by name when unknown) and the date of an absolvierter Lehrgang (null = not known) */
    private function completedEntry(string $courseName, ?string $completedOn): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $courseName));
        if ($name === '' || mb_strlen($name) > 150) {
            throw ValidationException::withMessages(['entry_course' => 'Bitte den Lehrgang angeben (höchstens 150 Zeichen).']);
        }
        // Without a date the Lehrgang counts as absolviert at an unknown time (old Lehrgänge entered afterwards)
        $date = null;
        if (filled($completedOn)) {
            $date = rescue(fn () => Carbon::createFromFormat('!Y-m-d', $completedOn), null, false);
            if (! $date || $date->gt(self::today()) || $date->year < 1950) {
                throw ValidationException::withMessages(['entry_date' => 'Das Datum ist ungültig oder liegt in der Zukunft (leer lassen, wenn es nicht bekannt ist).']);
            }
        }

        return [Course::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() ?? Course::create(['name' => $name]), $date];
    }

    /** Reminders this many days before a refresher is due */
    public const REFRESH_WARN_DAYS = 60;

    public function setRepeat(Course $course, ?int $months): void
    {
        if ($months !== null && ($months < 1 || $months > 120)) {
            throw ValidationException::withMessages(['repeat' => 'Das Wiederholungsintervall liegt zwischen 1 und 120 Monaten (leer = keins).']);
        }
        $course->update(['repeat_months' => $months]);
    }

    /**
     * Daily: absolvierte Lehrgänge whose refresher is due in REFRESH_WARN_DAYS (or overdue) – the Kamerad once per due
     * date, the Ausbildungsleiter one overview.
     */
    public function remindRefresh(): int
    {
        $limit = self::today()->addDays(self::REFRESH_WARN_DAYS);
        $due = Record::with(['course', 'user'])->where('status', 'completed')->whereNotNull('completed_on')
            ->whereHas('course', fn ($q) => $q->whereNotNull('repeat_months'))->get()
            ->filter(fn (Record $r) => $r->user?->is_active && $r->validUntil()->lte($limit) && ! ($r->refresh_reminded_on && Carbon::parse($r->refresh_reminded_on)->equalTo($r->validUntil())));
        foreach ($due as $record) {
            $record->update(['refresh_reminded_on' => $record->validUntil()]);
            $this->notify(collect([$record->user]), new RefreshDueNotification($record));
        }
        if ($due->isNotEmpty()) {
            $this->notify($this->managers(), new RefreshDigestNotification($due->values()));
        }

        return $due->count();
    }

    /** Mails the Kamerad 14 days before the first day, once; claimed atomically against a second scheduler */
    public function remindUpcoming(): int
    {
        $sent = 0;
        $today = self::today();

        $candidates = Record::where('status', 'confirmed')->whereNull('start_reminded_at')
            ->whereDate('starts_on', '>=', $today)->whereDate('starts_on', '<=', $today->copy()->addDays(self::REMIND_DAYS))
            ->pluck('id');

        foreach ($candidates as $id) {
            if (DB::table('lq_records')->where('id', $id)->whereNull('start_reminded_at')->update(['start_reminded_at' => now()]) !== 1) {
                continue;
            }

            $record = Record::with(['user', 'course', 'sessions'])->find($id);

            if ($record->user?->is_active) {
                $this->notify(collect([$record->user]), new CourseReminderNotification($record));
                $sent++;
            }
        }

        return $sent;
    }

    /** Ausbildungsleiter, or the admins if nobody holds the role */
    public function managers(): EloquentCollection
    {
        $managers = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', 'lehrgaenge')->where('role', 'ausbildungsleiter'))
            ->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    /** @return list<array{date: string, starts_at: ?string, ends_at: ?string, location: ?string}> sorted by day */
    private function validSessions(array $sessions): array
    {
        $time = fn ($v) => filled($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;

        $clean = collect($sessions)->filter(fn ($s) => filled($s['date'] ?? null))->map(function (array $s, $i) use ($time) {
            $date = rescue(fn () => Carbon::createFromFormat('!Y-m-d', $s['date']), null, false);

            if (! $date) {
                throw ValidationException::withMessages(["sessions.$i.date" => 'Ungültiges Datum.']);
            }

            if ((filled($s['starts_at'] ?? null) && ! $time($s['starts_at'])) || (filled($s['ends_at'] ?? null) && ! $time($s['ends_at']))) {
                throw ValidationException::withMessages(["sessions.$i.starts_at" => 'Bitte die Uhrzeit als HH:MM angeben.']);
            }

            return [
                'date' => $date->toDateString(),
                'starts_at' => $time($s['starts_at'] ?? null),
                'ends_at' => $time($s['ends_at'] ?? null),
                'location' => filled($s['location'] ?? null) ? mb_substr(trim($s['location']), 0, 255) : null,
            ];
        })->sortBy(fn ($s) => $s['date'].($s['starts_at'] ?? ''))->values();

        if ($clean->isEmpty()) {
            throw ValidationException::withMessages(['sessions' => 'Bitte mindestens einen Lehrgangstag eintragen.']);
        }

        return $clean->all();
    }

    private function assertStatus(Record $record, array $allowed): void
    {
        if (! in_array($record->status, $allowed, true)) {
            throw ValidationException::withMessages(['record' => 'Das geht bei „'.$record->statusLabel().'“ nicht (mehr).']);
        }
    }

    private function notify(Collection $users, Notification $notification): void
    {
        $users->filter()->each(function (User $user) use ($notification) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
