<?php

namespace Modules\Kalender\src\Services;

use App\Models\MemberFunction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notifier;
use Illuminate\Validation\ValidationException;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Notifications\AttendanceMissingNotification;
use Modules\Kalender\src\Notifications\AttendanceReportNotification;
use Throwable;

/**
 * "Anwesenheit abschließen": after a Dienst the Wehrführer or a Stellvertreter closes the attendance and the
 * list goes by mail (with CSV) to the configured recipients; without it, 24 hours after the end automatically.
 * A change afterwards can be sent as Korrektur. Settings in kalender.attendance; null lists mean the defaults.
 */
class AttendanceService
{
    public const SETTING = 'kalender.attendance';

    public const AUTO_AFTER_HOURS = 24;

    public function __construct(private readonly EventService $events) {}

    /** @return array{types: list<string>, closer_function_ids: ?list<int>, recipient_function_ids: ?list<int>, recipient_user_ids: list<int>, recipient_emails: list<string>} */
    public function settings(): array
    {
        $stored = json_decode((string) Setting::get(self::SETTING, ''), true) ?: [];

        return [
            'types' => array_values((array) ($stored['types'] ?? ['uebung'])),
            'closer_function_ids' => isset($stored['closer_function_ids']) ? array_map('intval', (array) $stored['closer_function_ids']) : null,
            'recipient_function_ids' => isset($stored['recipient_function_ids']) ? array_map('intval', (array) $stored['recipient_function_ids']) : null,
            'recipient_user_ids' => array_map('intval', (array) ($stored['recipient_user_ids'] ?? [])),
            'recipient_emails' => array_values((array) ($stored['recipient_emails'] ?? [])),
        ];
    }

    public function saveSettings(array $settings): void
    {
        Setting::set(self::SETTING, json_encode($settings));
    }

    /** Wehrführer and Stellvertreter by default: every Funktion with "Wehrführer" in its name */
    public static function defaultCloserFunctionIds(): array
    {
        return MemberFunction::idsWithChildren(MemberFunction::whereRaw('lower(name) like ?', ['%wehrführer%'])->pluck('id')->all());
    }

    /** The Wehrführer by default: the Funktion called "Wehrführer" or "Wehrführerin" */
    public static function defaultRecipientFunctionIds(): array
    {
        return MemberFunction::idsWithChildren(MemberFunction::whereRaw('lower(name) in (?, ?)', ['wehrführer', 'wehrführerin'])->pluck('id')->all());
    }

    public function closerFunctionIds(): array
    {
        return $this->settings()['closer_function_ids'] ?? self::defaultCloserFunctionIds();
    }

    public function recipientFunctionIds(): array
    {
        return $this->settings()['recipient_function_ids'] ?? self::defaultRecipientFunctionIds();
    }

    /** Events whose attendance is closed and sent: own Termine of the chosen Arten, not cancelled */
    public function appliesTo(Event $event): bool
    {
        return ! $event->isExternal() && ! $event->cancelled_at && in_array($event->type, $this->settings()['types'], true);
    }

    /** Admins and the holders of the closing Funktionen */
    public function mayClose(User $user, Event $event): bool
    {
        if (! $this->appliesTo($event) || ! $user->is_active || ! $user->canUseModule('kalender')) {
            return false;
        }

        return $user->is_admin || (($ids = $this->closerFunctionIds()) !== [] && $user->functions()->whereIn('member_functions.id', $ids)->exists());
    }

    /** Active holders of the closing Funktionen, the admins when there are none */
    public function closers(): EloquentCollection
    {
        $ids = $this->closerFunctionIds();
        $users = $ids === [] ? new EloquentCollection : User::active()->whereHas('functions', fn ($q) => $q->whereIn('member_functions.id', $ids))->get();

        return $users->isNotEmpty() ? $users : User::active()->where('is_admin', true)->get();
    }

    /**
     * Kameraden by Funktion or by name, plus free addresses (e.g. the Gemeinde); the admins when nothing is set.
     *
     * @return array{users: EloquentCollection<int, User>, emails: list<string>}
     */
    public function recipients(): array
    {
        $settings = $this->settings();
        $functionIds = $this->recipientFunctionIds();
        $users = User::active()->whereNotNull('email')
            ->where(fn ($q) => $q->whereIn('id', $settings['recipient_user_ids'] ?: [0])
                ->orWhereHas('functions', fn ($f) => $f->whereIn('member_functions.id', $functionIds ?: [0])))
            ->orderBy('name')->get();
        $emails = array_values(array_diff($settings['recipient_emails'], $users->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all()));

        if ($users->isEmpty() && $emails === []) {
            $users = User::active()->whereNotNull('email')->where('is_admin', true)->orderBy('name')->get();
        }

        return ['users' => $users, 'emails' => $emails];
    }

    /** "Hanna Hydrant, gemeinde@example.com" for the hint on the event page */
    public function recipientLabel(): string
    {
        $recipients = $this->recipients();

        return collect([...$recipients['users']->pluck('name')->all(), ...$recipients['emails']])->implode(', ') ?: 'niemanden (keine Empfänger eingestellt)';
    }

    /** By hand ($by) or automatically (null); only once, the first one wins */
    public function close(Event $event, ?User $by): void
    {
        if (! $event->hasStarted()) {
            throw ValidationException::withMessages(['attendance' => 'Die Anwesenheit lässt sich erst ab Beginn des Termins abschließen.']);
        }
        $claimed = DB::table('kal_events')->where('id', $event->id)->whereNull('attendance_closed_at')
            ->update(['attendance_closed_at' => now(), 'attendance_closed_by' => $by?->id, 'attendance_changed_at' => null]);
        if ($claimed !== 1) {
            throw ValidationException::withMessages(['attendance' => 'Die Anwesenheit ist schon abgeschlossen.']);
        }

        $this->send($event->refresh(), false);
    }

    /** After a change since closing: the list again, marked as Korrektur */
    public function sendCorrection(Event $event, User $by): void
    {
        $claimed = DB::table('kal_events')->where('id', $event->id)->whereNotNull('attendance_closed_at')->whereNotNull('attendance_changed_at')
            ->update(['attendance_changed_at' => null, 'attendance_closed_at' => now(), 'attendance_closed_by' => $by->id]);
        if ($claimed !== 1) {
            throw ValidationException::withMessages(['attendance' => 'Seit dem Abschluss hat sich nichts geändert.']);
        }

        $this->send($event->refresh(), true);
    }

    /**
     * Hourly: events over for 24 hours are closed and sent; without any attendance the closers are
     * reminded once instead (no empty list goes out).
     *
     * @return array{sent: int, reminded: int}
     */
    public function closeDue(): array
    {
        $done = ['sent' => 0, 'reminded' => 0];
        $due = Event::whereNull('attendance_closed_at')->whereNull('source_id')->whereNull('cancelled_at')
            ->whereIn('type', $this->settings()['types'])
            ->where('ends_at', '<=', now()->subHours(self::AUTO_AFTER_HOURS))
            ->get();

        foreach ($due as $event) {
            if ($event->responses()->where('attended', true)->exists()) {
                try {
                    $this->close($event, null);
                    $done['sent']++;
                } catch (ValidationException) {
                    // closed by hand in the meantime
                }
            } elseif (DB::table('kal_events')->where('id', $event->id)->whereNull('attendance_reminded_at')->update(['attendance_reminded_at' => now()]) === 1) {
                $this->notify($this->closers(), new AttendanceMissingNotification($event));
                $done['reminded']++;
            }
        }

        return $done;
    }

    private function send(Event $event, bool $correction): void
    {
        $rows = $this->events->participantRows($event);
        $notification = new AttendanceReportNotification($event, $rows->where('attended', 'ja')->values(), $rows->count(), $this->events->participantsCsv($event), $correction);
        $recipients = $this->recipients();

        $this->notify($recipients['users'], $notification);
        foreach ($recipients['emails'] as $email) {
            try {
                Notifier::route('mail', $email)->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * A failed mail is reported, never thrown: closing stays done
     *
     * @param  EloquentCollection<int, User>  $users
     */
    private function notify(EloquentCollection $users, Notification $notification): void
    {
        foreach ($users as $user) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
