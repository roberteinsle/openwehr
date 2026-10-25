<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\HealthProblemsNotification;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operating figures for the admin "Analyse": heartbeat (uptime), scheduled commands, version since
 * when, backup reports, the TLS certificate, disk and database size – and the daily check that
 * tells the admins when something needs them.
 */
class SystemStatus
{
    public const VERSION_KEY = 'system.version';

    public const TLS_KEY = 'system.tls';

    public const BACKUP_KEY = 'system.backup';

    public const BACKUP_TOKEN_KEY = 'system.backup_token';

    /** A backup older than this is a problem */
    public const BACKUP_MAX_HOURS = 26;

    public const TLS_WARN_DAYS = 14;

    public const DISK_WARN_PERCENT = 90;

    // ─── heartbeat, version ──────────────────────────────────────────────────

    /** Every minute from the scheduler: the minute counts as "up"; a new version is noted with its date */
    public function heartbeat(): void
    {
        DB::table('heartbeats')->insertOrIgnore(['minute' => now()->startOfMinute()]);
        $version = (string) config('app.version');
        $seen = json_decode((string) Setting::get(self::VERSION_KEY, ''), true) ?: [];
        if (($seen['version'] ?? null) !== $version) {
            Setting::set(self::VERSION_KEY, json_encode(['version' => $version, 'since' => now()->toIso8601String()]));
        }
    }

    /** @return array{version: string, since: ?Carbon} */
    public function version(): array
    {
        $seen = json_decode((string) Setting::get(self::VERSION_KEY, ''), true) ?: [];

        return ['version' => (string) config('app.version'),
            'since' => ($seen['version'] ?? null) === config('app.version') && isset($seen['since']) ? Carbon::parse($seen['since']) : null];
    }

    /**
     * Share of minutes with a heartbeat in the last $days (only since the first heartbeat, so a new
     * installation is not "down" before it existed), and the longest gaps.
     *
     * @return array{percent: ?float, since: ?Carbon, gaps: list<array{from: Carbon, minutes: int}>}
     */
    public function uptime(int $days): array
    {
        $from = now()->subDays($days)->startOfMinute();
        $first = DB::table('heartbeats')->where('minute', '>=', $from)->min('minute');
        if (! $first) {
            return ['percent' => null, 'since' => null, 'gaps' => []];
        }
        $first = Carbon::parse($first);
        $expected = max(1, (int) $first->diffInMinutes(now()->startOfMinute()) + 1);
        $seen = DB::table('heartbeats')->where('minute', '>=', $first)->count();
        $gaps = collect(DB::select(
            'SELECT minute, next_minute FROM (SELECT minute, LEAD(minute) OVER (ORDER BY minute) AS next_minute FROM heartbeats WHERE minute >= ?) g
             WHERE next_minute - minute > interval \'5 minutes\' ORDER BY next_minute - minute DESC LIMIT 5', [$first]))
            ->map(fn ($g) => ['from' => Carbon::parse($g->minute), 'minutes' => (int) Carbon::parse($g->minute)->diffInMinutes(Carbon::parse($g->next_minute)) - 1])->all();

        return ['percent' => round(min(100, $seen * 100 / $expected), 2), 'since' => $first, 'gaps' => $gaps];
    }

    // ─── scheduled commands ──────────────────────────────────────────────────

    public function scheduleStarting(ScheduledTaskStarting $event): void
    {
        $this->touchRun(self::commandName($event->task), ['last_started_at' => now()]);
    }

    public function scheduleFinished(ScheduledTaskFinished $event): void
    {
        $command = self::commandName($event->task);
        $failed = $event->task->exitCode !== null && $event->task->exitCode !== 0;
        $this->touchRun($command, ['last_finished_at' => now()] + ($failed ? ['last_failed_at' => now(), 'last_error' => 'Exit-Code '.$event->task->exitCode] : []), $failed);
    }

    public function scheduleFailed(ScheduledTaskFailed $event): void
    {
        $this->touchRun(self::commandName($event->task), ['last_failed_at' => now(), 'last_error' => mb_substr($event->exception->getMessage(), 0, 1000)], true);
    }

    private function touchRun(string $command, array $values, bool $failed = false): void
    {
        try {
            DB::table('schedule_runs')->insertOrIgnore(['command' => $command]);
            DB::table('schedule_runs')->where('command', $command)->update($values + [
                'runs' => DB::raw(isset($values['last_started_at']) ? 'runs + 1' : 'runs'),
                'failures' => DB::raw($failed ? 'failures + 1' : 'failures'),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** "lehrgaenge:remind-refresh" instead of the whole PHP call */
    public static function commandName($task): string
    {
        $command = (string) ($task->description ?: $task->command);
        if (preg_match("/artisan'?\\s+'?([\\w:.-]+)/", $command, $m)) {
            return $m[1];
        }

        return mb_substr(trim($command), 0, 255) ?: 'Aufgabe';
    }

    // ─── backups ─────────────────────────────────────────────────────────────

    /** The secret part of the backup webhook URL; created on first use */
    public function backupToken(): string
    {
        $token = (string) Setting::get(self::BACKUP_TOKEN_KEY, '');
        if ($token === '') {
            $token = Str::random(40);
            Setting::set(self::BACKUP_TOKEN_KEY, $token);
        }

        return $token;
    }

    public function newBackupToken(): void
    {
        Setting::set(self::BACKUP_TOKEN_KEY, Str::random(40));
    }

    /** A backup job (or an admin by hand) reports a backup */
    public function reportBackup(string $source, bool $ok = true, ?string $note = null): void
    {
        Setting::set(self::BACKUP_KEY, json_encode(['at' => now()->toIso8601String(), 'source' => $source, 'ok' => $ok, 'note' => $note ? mb_substr($note, 0, 200) : null]));
    }

    /** @return ?array{at: Carbon, source: string, ok: bool, note: ?string, hours: int} */
    public function lastBackup(): ?array
    {
        $b = json_decode((string) Setting::get(self::BACKUP_KEY, ''), true);
        if (! $b || ! isset($b['at'])) {
            return null;
        }
        $at = Carbon::parse($b['at']);

        return ['at' => $at, 'source' => (string) $b['source'], 'ok' => (bool) $b['ok'], 'note' => $b['note'] ?? null, 'hours' => (int) $at->diffInHours(now())];
    }

    // ─── TLS certificate ─────────────────────────────────────────────────────

    /** Reads the certificate of APP_URL (only for https) and stores its expiry */
    public function checkCertificate(): void
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST);
        $result = ['checked_at' => now()->toIso8601String(), 'host' => $host, 'valid_to' => null, 'error' => null];
        if (! str_starts_with($url, 'https://') || ! $host) {
            $result['error'] = 'APP_URL ist keine https-Adresse.';
        } else {
            try {
                $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host, 'verify_peer' => false, 'verify_peer_name' => false]]);
                $client = @stream_socket_client('ssl://'.$host.':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
                if (! $client) {
                    throw new \RuntimeException($errstr ?: 'keine Verbindung');
                }
                $cert = openssl_x509_parse(stream_context_get_params($client)['options']['ssl']['peer_certificate']);
                fclose($client);
                $result['valid_to'] = Carbon::createFromTimestamp($cert['validTo_time_t'])->toIso8601String();
                $result['issuer'] = $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? null;
            } catch (Throwable $e) {
                $result['error'] = 'Zertifikat nicht lesbar: '.mb_substr($e->getMessage(), 0, 200);
            }
        }
        Setting::set(self::TLS_KEY, json_encode($result));
    }

    /** @return ?array{checked_at: Carbon, host: ?string, valid_to: ?Carbon, days: ?int, issuer: ?string, error: ?string} */
    public function certificate(): ?array
    {
        $c = json_decode((string) Setting::get(self::TLS_KEY, ''), true);
        if (! $c) {
            return null;
        }
        $to = $c['valid_to'] ? Carbon::parse($c['valid_to']) : null;

        return ['checked_at' => Carbon::parse($c['checked_at']), 'host' => $c['host'] ?? null, 'valid_to' => $to,
            'days' => $to ? (int) floor(now()->diffInDays($to, false)) : null, 'issuer' => $c['issuer'] ?? null, 'error' => $c['error'] ?? null];
    }

    // ─── resources ───────────────────────────────────────────────────────────

    /** @return array{disk_free: ?int, disk_total: ?int, disk_percent: ?int, db_bytes: int, tables: list<array{name: string, bytes: int, rows: int}>} */
    public function resources(): array
    {
        $free = @disk_free_space(storage_path()) ?: null;
        $total = @disk_total_space(storage_path()) ?: null;

        return [
            'disk_free' => $free ? (int) $free : null, 'disk_total' => $total ? (int) $total : null,
            'disk_percent' => $free && $total ? (int) round(100 - $free * 100 / $total) : null,
            'db_bytes' => (int) DB::selectOne('SELECT pg_database_size(current_database()) AS b')->b,
            'tables' => collect(DB::select('SELECT relname AS name, pg_total_relation_size(relid) AS bytes, n_live_tup AS rows FROM pg_stat_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 8'))
                ->map(fn ($t) => ['name' => $t->name, 'bytes' => (int) $t->bytes, 'rows' => (int) $t->rows])->all(),
        ];
    }

    // ─── the daily check ─────────────────────────────────────────────────────

    /** @return list<string> what needs the admins now */
    public function problems(): array
    {
        $problems = [];
        $backup = $this->lastBackup();
        if ($backup && ($backup['hours'] > self::BACKUP_MAX_HOURS || ! $backup['ok'])) {
            $problems[] = $backup['ok'] ? 'Letztes gemeldetes Backup ist '.$backup['hours'].' Stunden alt.' : 'Das letzte Backup ist fehlgeschlagen.';
        }
        $tls = $this->certificate();
        if ($tls && $tls['days'] !== null && $tls['days'] < self::TLS_WARN_DAYS) {
            $problems[] = 'Das TLS-Zertifikat läuft in '.$tls['days'].' Tagen ab ('.$tls['valid_to']->setTimezone('Europe/Berlin')->format('d.m.Y').').';
        }
        $failed = DB::table('schedule_runs')->where('last_failed_at', '>=', now()->subDay())->pluck('command');
        if ($failed->isNotEmpty()) {
            $problems[] = 'Geplante Aufgaben mit Fehler in den letzten 24 Stunden: '.$failed->implode(', ').'.';
        }
        $resources = rescue(fn () => $this->resources(), null, false);
        if (($resources['disk_percent'] ?? 0) >= self::DISK_WARN_PERCENT) {
            $problems[] = 'Der Speicherplatz ist zu '.$resources['disk_percent'].' % belegt.';
        }
        $lastBeat = DB::table('heartbeats')->max('minute');
        if ($lastBeat && Carbon::parse($lastBeat)->lt(now()->subMinutes(10))) {
            $problems[] = 'Der Scheduler lief seit '.Carbon::parse($lastBeat)->setTimezone('Europe/Berlin')->format('d.m. H:i').' nicht mehr.';
        }

        return $problems;
    }

    /** Daily: refresh the certificate, then tell the admins about problems (one mail) */
    public function dailyCheck(): int
    {
        $this->checkCertificate();
        $problems = $this->problems();
        if ($problems !== []) {
            User::active()->where('is_admin', true)->get()->each(fn (User $admin) => rescue(fn () => $admin->notify(new HealthProblemsNotification($problems))));
        }

        return count($problems);
    }
}
