<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AppError;
use App\Models\MailLog;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Notifications\MagicLinkNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the admin "Analyse" that come from the logs: errors, requests, mail, logins and
 * rights, usage per module and the growth of the data. Usage is only counted per module and
 * area, never per person – apart from the last login, which the Kameraden list shows anyway.
 */
class Analytics
{
    /** @return array{total: int, places: int, latest: Collection<int, AppError>} errors of the last $days */
    public function errors(int $days = 7): array
    {
        $recent = AppError::where('last_at', '>=', now()->subDays($days));

        return ['total' => (int) (clone $recent)->sum('count'), 'places' => (clone $recent)->count(), 'latest' => (clone $recent)->orderByDesc('last_at')->limit(8)->get()];
    }

    /** @return array{count: int, server_errors: int, avg_ms: ?int, max_ms: ?int, days: list<array{day: string, count: int, avg_ms: ?int}>} page requests of the last $days */
    public function requests(int $days = 7): array
    {
        $from = now()->subDays($days)->startOfDay();
        $sum = DB::table('request_metrics')->where('hour', '>=', $from)
            ->selectRaw('COALESCE(SUM(count), 0) AS c, COALESCE(SUM(server_errors), 0) AS e, COALESCE(SUM(total_ms), 0) AS t, MAX(max_ms) AS m')->first();
        $daily = DB::table('request_metrics')->where('hour', '>=', $from)
            ->selectRaw("to_char(hour AT TIME ZONE 'Europe/Berlin', 'YYYY-MM-DD') AS day, SUM(count) AS c, SUM(total_ms) AS t")
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn ($d) => ['day' => Carbon::parse($d->day)->format('d.m.'), 'count' => (int) $d->c, 'avg_ms' => $d->c ? (int) round($d->t / $d->c) : null])->all();

        return ['count' => (int) $sum->c, 'server_errors' => (int) $sum->e, 'avg_ms' => $sum->c ? (int) round($sum->t / $sum->c) : null,
            'max_ms' => $sum->m !== null ? (int) $sum->m : null, 'days' => $daily];
    }

    /** @return array{sent_7: int, sent_30: int, failed_30: int, last_failure: ?AppError} */
    public function mail(): array
    {
        $failures = AppError::where('last_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->where('class', 'ilike', '%Mailer%')->orWhere('class', 'ilike', '%Transport%')->orWhere('message', 'ilike', '%smtp%'));

        return ['sent_7' => MailLog::where('sent_at', '>=', now()->subDays(7))->count(), 'sent_30' => MailLog::where('sent_at', '>=', now()->subDays(30))->count(),
            'failed_30' => (int) (clone $failures)->sum('count'), 'last_failure' => (clone $failures)->orderByDesc('last_at')->first()];
    }

    /** @return Collection<int, \stdClass> every scheduled command with its last run and failures */
    public function scheduleRuns(): Collection
    {
        return DB::table('schedule_runs')->orderByRaw('last_failed_at IS NULL, last_failed_at DESC')->orderBy('command')->get()
            ->map(function ($r) {
                foreach (['last_started_at', 'last_finished_at', 'last_failed_at'] as $f) {
                    $r->{$f} = $r->{$f} ? Carbon::parse($r->{$f}) : null;
                }

                return $r;
            });
    }

    /** @return array{logins: int, failed: int, throttled: int, links: int, mail_changes: int, rights: Collection<int, ActivityLog>, latest_failed: Collection<int, ActivityLog>} the last $days */
    public function security(int $days = 30): array
    {
        $since = now()->subDays($days);
        $count = fn (string $event) => ActivityLog::where('event', $event)->where('created_at', '>=', $since)->count();

        return [
            'logins' => $count('login'), 'failed' => $count('login_failed'), 'throttled' => $count('login_throttled'),
            'links' => MailLog::where('type', MagicLinkNotification::class)->where('sent_at', '>=', $since)->count(),
            'mail_changes' => MailLog::where('type', EmailChangedNotification::class)->where('sent_at', '>=', $since)->count(),
            // Admin flag, status and module roles: who changed whose rights
            'rights' => ActivityLog::with('user')->where('created_at', '>=', $since)
                ->where(fn ($q) => $q->where('subject_type', 'App\\Models\\ModuleUser')
                    ->orWhere(fn ($u) => $u->where('subject_type', User::class)->where('event', 'updated')
                        ->where(fn ($c) => $c->whereRaw("jsonb_exists(changes::jsonb, 'is_admin')")->orWhereRaw("jsonb_exists(changes::jsonb, 'status')"))))
                ->latest('created_at')->limit(10)->get(),
            'latest_failed' => ActivityLog::whereIn('event', ['login_failed', 'login_throttled'])->where('created_at', '>=', $since)->latest('created_at')->limit(5)->get(),
        ];
    }

    /** @return Collection<int, ActivityLog> */
    public function latestAudit(int $limit = 5): Collection
    {
        return ActivityLog::with('user')->whereNotIn('event', ['login_failed', 'login_throttled'])->latest('created_at')->latest('id')->limit($limit)->get();
    }

    /** @return Collection<int, User> active Kameraden with their last login, the longest absent on top (never first) */
    public function lastLogins(): Collection
    {
        return User::active()->orderByRaw('last_login_at IS NOT NULL, last_login_at ASC')->get(['id', 'name', 'email', 'last_login_at']);
    }

    /**
     * Per active module: page requests (30 and 90 days) and changed records (90 days) – what is used,
     * what never. Counted per module, not per person.
     *
     * @return list<array{key: string, name: string, views_30: int, views_90: int, changes_90: int}>
     */
    public function moduleUsage(): array
    {
        $registry = app(ModuleRegistry::class);
        $views = fn (int $days) => DB::table('request_metrics')->where('hour', '>=', now()->subDays($days))->groupBy('area')->selectRaw('area, SUM(count) AS c')->pluck('c', 'area');
        $v30 = $views(30);
        $v90 = $views(90);
        $changes = ActivityLog::where('created_at', '>=', now()->subDays(90))->where('subject_type', 'like', 'Modules\\\\%')
            ->selectRaw("split_part(subject_type, '\\', 2) AS module, COUNT(*) AS c")->groupBy('module')->pluck('c', 'module');

        return collect($registry->all())->filter(fn ($def, $key) => $registry->isActive($key))
            ->map(function ($def, $key) use ($v30, $v90, $changes) {
                $namespace = explode('\\', (string) ($def['provider'] ?? ''))[1] ?? '';

                return ['key' => $key, 'name' => $def['name'], 'views_30' => (int) ($v30[$key] ?? 0), 'views_90' => (int) ($v90[$key] ?? 0), 'changes_90' => (int) ($changes[$namespace] ?? 0)];
            })->sortByDesc(fn ($m) => $m['views_90'] + $m['changes_90'])->values()->all();
    }

    /** @return list<array{table: string, rows: int, new_30: ?int}> the biggest tables with the records of the last 30 days (cached for an hour) */
    public function records(): array
    {
        return Cache::remember('analytics.records', now()->addHour(), function () {
            $technical = ['heartbeats', 'request_metrics', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens'];
            $withCreated = collect(DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'created_at'"))->pluck('table_name')->all();

            return collect(DB::select('SELECT relname AS name, n_live_tup AS rows FROM pg_stat_user_tables ORDER BY n_live_tup DESC LIMIT 40'))
                ->reject(fn ($t) => in_array($t->name, $technical, true))
                ->map(fn ($t) => ['table' => $t->name, 'rows' => (int) DB::table($t->name)->count(),
                    'new_30' => in_array($t->name, $withCreated, true) ? DB::table($t->name)->where('created_at', '>=', now()->subDays(30))->count() : null])
                ->sortByDesc('rows')->take(15)->values()->all();
        });
    }
}
