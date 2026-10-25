<?php

namespace App\Services;

use App\Models\AppError;
use App\Models\User;
use App\Notifications\AppErrorNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps every reported exception for the admin "Analyse", grouped by class and place, and tells the
 * admins (mail, bell) about an error at most once a day per place. Never throws: a broken database
 * must not turn one error into two.
 */
class ErrorRecorder
{
    public const NOTIFY_EVERY_HOURS = 24;

    private bool $busy = false;

    public function record(Throwable $e): void
    {
        if ($this->busy) {
            return;
        }
        $this->busy = true;
        try {
            $location = str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine();
            $fingerprint = sha1($e::class.'|'.$location);
            $url = app()->runningInConsole() ? 'Konsole: '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3)) : request()->method().' '.request()->path();
            $now = now();
            DB::table('app_errors')->upsert([[
                'fingerprint' => $fingerprint, 'class' => mb_substr($e::class, 0, 255), 'message' => mb_substr($e->getMessage(), 0, 2000),
                'location' => mb_substr($location, 0, 255), 'url' => mb_substr($url, 0, 255), 'count' => 1, 'first_at' => $now, 'last_at' => $now,
            ]], ['fingerprint'], ['message', 'url', 'last_at', 'count' => DB::raw('app_errors.count + 1')]);

            $error = AppError::where('fingerprint', $fingerprint)->first();
            // Claim the notification atomically, so two requests don't both mail
            $claimed = $error && AppError::whereKey($error->id)
                ->where(fn ($q) => $q->whereNull('notified_at')->orWhere('notified_at', '<', $now->copy()->subHours(self::NOTIFY_EVERY_HOURS)))
                ->update(['notified_at' => $now]);
            if ($claimed) {
                User::active()->where('is_admin', true)->get()->each(fn (User $admin) => rescue(fn () => $admin->notify(new AppErrorNotification($error)), report: false));
            }
        } catch (Throwable) {
            // nothing: the original error is reported by Laravel anyway
        } finally {
            $this->busy = false;
        }
    }
}
