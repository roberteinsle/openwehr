@php
    $tz = 'Europe/Berlin';
    $bytes = fn (?int $b) => $b === null ? '–' : ($b >= 1073741824 ? number_format($b / 1073741824, 1, ',', '.').' GB' : number_format($b / 1048576, 1, ',', '.').' MB');
    $ago = fn ($d) => $d ? $d->copy()->setTimezone($tz)->format('d.m.Y H:i').' ('.$d->locale('de')->diffForHumans().')' : '–';
    $num = fn ($n) => number_format((int) $n, 0, ',', '.');
@endphp
<x-filament-panels::page>
    <style>
        .an-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); }
        .an-tile { border: 1px solid rgb(0 0 0 / .08); border-radius: .75rem; padding: .9rem 1rem; }
        .dark .an-tile { border-color: rgb(255 255 255 / .1); }
        .an-tile h3 { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .65; margin-bottom: .35rem; }
        .an-big { font-size: 1.6rem; font-weight: 700; line-height: 1.2; }
        .an-small { font-size: .8rem; opacity: .75; }
        .an-ok { color: rgb(22 163 74); } .an-warn { color: rgb(217 119 6); } .an-bad { color: rgb(220 38 38); }
        .an-table { width: 100%; font-size: .85rem; border-collapse: collapse; }
        .an-table th { text-align: left; font-weight: 600; opacity: .7; padding: .3rem .4rem; border-bottom: 1px solid rgb(0 0 0 / .1); }
        .an-table td { padding: .3rem .4rem; border-bottom: 1px solid rgb(0 0 0 / .05); vertical-align: top; }
        .an-table td.n, .an-table th.n { text-align: right; white-space: nowrap; }
        .an-banner { border-radius: .75rem; padding: .8rem 1rem; font-size: .9rem; }
        .an-banner.ok { background: rgb(22 163 74 / .1); color: rgb(21 128 61); }
        .an-banner.bad { background: rgb(220 38 38 / .1); color: rgb(185 28 28); }
        .an-code { font-family: ui-monospace, monospace; font-size: .75rem; word-break: break-all; background: rgb(0 0 0 / .05); padding: .25rem .4rem; border-radius: .3rem; }
        .an-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .6rem; }
    </style>

    @if($problems)
        <div class="an-banner bad"><strong>Braucht Aufmerksamkeit:</strong> {{ implode(' ', $problems) }}</div>
    @else
        <div class="an-banner ok">Alles in Ordnung – die tägliche Prüfung (7:15 Uhr) meldet sich bei den Admins, sobald etwas nicht stimmt.</div>
    @endif

    {{-- 1. Betrieb --}}
    <x-filament::section heading="Betrieb" description="Läuft die App, und muss jemand eingreifen?">
        <div class="an-grid">
            <div class="an-tile">
                <h3>Fehler (7 Tage)</h3>
                <p class="an-big {{ $appErrors['total'] ? 'an-bad' : 'an-ok' }}">{{ $num($appErrors['total']) }}</p>
                <p class="an-small">an {{ $appErrors['places'] }} Stelle{{ $appErrors['places'] === 1 ? '' : 'n' }} · Admins bekommen je Stelle höchstens einmal am Tag eine Nachricht</p>
            </div>
            <div class="an-tile">
                <h3>Erreichbarkeit</h3>
                <p class="an-big {{ ($uptime7['percent'] ?? 100) < 99 ? 'an-warn' : 'an-ok' }}">{{ $uptime7['percent'] !== null ? number_format($uptime7['percent'], 2, ',', '.').' %' : '–' }}</p>
                <p class="an-small">7 Tage · 30 Tage: {{ $uptime30['percent'] !== null ? number_format($uptime30['percent'], 2, ',', '.').' %' : '–' }} (gemessen am Scheduler jede Minute{{ $uptime30['since'] ? ', seit '.$uptime30['since']->setTimezone($tz)->format('d.m.Y') : '' }})</p>
                @foreach($uptime30['gaps'] as $gap)<p class="an-small an-warn">Ausfall ab {{ $gap['from']->setTimezone($tz)->format('d.m. H:i') }}: {{ $gap['minutes'] }} Min.</p>@endforeach
            </div>
            <div class="an-tile">
                <h3>Antwortzeiten (7 Tage)</h3>
                <p class="an-big">{{ $requests['avg_ms'] !== null ? $requests['avg_ms'].' ms' : '–' }}</p>
                <p class="an-small">Durchschnitt · langsamste {{ $requests['max_ms'] ?? '–' }} ms · {{ $num($requests['count']) }} Aufrufe · <span class="{{ $requests['server_errors'] ? 'an-bad' : '' }}">{{ $requests['server_errors'] }} Serverfehler (HTTP 5xx)</span></p>
            </div>
            <div class="an-tile">
                <h3>Backups</h3>
                @if($backup)
                    <p class="an-big {{ ! $backup['ok'] ? 'an-bad' : ($backup['hours'] > \App\Services\SystemStatus::BACKUP_MAX_HOURS ? 'an-warn' : 'an-ok') }}">{{ $backup['ok'] ? 'vor '.$backup['hours'].' Std.' : 'fehlgeschlagen' }}</p>
                    <p class="an-small">{{ $backup['at']->setTimezone($tz)->format('d.m.Y H:i') }} · {{ $backup['source'] }}{{ $backup['note'] ? ' · '.$backup['note'] : '' }}</p>
                @else
                    <p class="an-big an-warn">keine Meldung</p>
                    <p class="an-small">Die Backups macht der Server (z. B. Coolify). Damit openwehr ihr Alter kennt, ruft der Backup-Job nach jedem Lauf die Webhook-Adresse auf – oder ein Admin bestätigt die Prüfung von Hand.</p>
                @endif
                @if($showToken)<p class="an-code mt-2">{{ $backupUrl }}</p><p class="an-small">GET oder POST, bei Fehler mit <code>?status=failed</code>. Geheim halten.</p>@endif
                <div class="an-actions">
                    <x-filament::button size="xs" color="gray" wire:click="$toggle('showToken')">{{ $showToken ? 'Adresse verbergen' : 'Webhook-Adresse zeigen' }}</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="confirmBackup">Heute geprüft</x-filament::button>
                    <x-filament::button size="xs" color="gray" wire:click="newBackupToken" wire:confirm="Neue Adresse erzeugen? Die bisherige gilt dann nicht mehr.">Neue Adresse</x-filament::button>
                </div>
            </div>
            <div class="an-tile">
                <h3>Mailversand</h3>
                <p class="an-big {{ $mail['failed_30'] ? 'an-bad' : 'an-ok' }}">{{ $mail['failed_30'] }} fehlgeschlagen</p>
                <p class="an-small">30 Tage · verschickt: {{ $num($mail['sent_7']) }} in 7, {{ $num($mail['sent_30']) }} in 30 Tagen</p>
                @if($mail['last_failure'])<p class="an-small an-bad">Zuletzt {{ $mail['last_failure']->last_at->setTimezone($tz)->format('d.m. H:i') }}: {{ \Illuminate\Support\Str::limit($mail['last_failure']->message, 120) }}</p>@endif
                @foreach($services['checks'] as $check)
                    <p class="an-small {{ ['ok' => 'an-ok', 'warning' => 'an-warn', 'error' => 'an-bad'][$check['status']] ?? '' }}">{{ $check['label'] }}: {{ $check['message'] }}</p>
                @endforeach
                <div class="an-actions"><x-filament::button size="xs" color="gray" wire:click="checkServices">Dienste jetzt prüfen</x-filament::button></div>
            </div>
            <div class="an-tile">
                <h3>Ressourcen</h3>
                @if($resources)
                    <p class="an-big {{ ($resources['disk_percent'] ?? 0) >= \App\Services\SystemStatus::DISK_WARN_PERCENT ? 'an-bad' : '' }}">{{ $resources['disk_percent'] !== null ? $resources['disk_percent'].' % belegt' : '–' }}</p>
                    <p class="an-small">frei {{ $bytes($resources['disk_free']) }} von {{ $bytes($resources['disk_total']) }} · Datenbank {{ $bytes($resources['db_bytes']) }}</p>
                    <p class="an-small">Größte Tabellen: {{ collect($resources['tables'])->map(fn ($t) => $t['name'].' '.$bytes($t['bytes']))->implode(', ') }}</p>
                @else
                    <p class="an-small">Nicht lesbar.</p>
                @endif
            </div>
            <div class="an-tile">
                <h3>TLS-Zertifikat</h3>
                @if($certificate && $certificate['valid_to'])
                    <p class="an-big {{ $certificate['days'] < \App\Services\SystemStatus::TLS_WARN_DAYS ? 'an-bad' : ($certificate['days'] < 30 ? 'an-warn' : 'an-ok') }}">noch {{ $certificate['days'] }} Tage</p>
                    <p class="an-small">{{ $certificate['host'] }} · gültig bis {{ $certificate['valid_to']->setTimezone($tz)->format('d.m.Y') }}{{ $certificate['issuer'] ? ' · '.$certificate['issuer'] : '' }} · geprüft {{ $certificate['checked_at']->setTimezone($tz)->format('d.m. H:i') }}</p>
                @else
                    <p class="an-big an-warn">{{ $certificate ? 'unbekannt' : 'noch nicht geprüft' }}</p>
                    @if($certificate['error'] ?? null)<p class="an-small">{{ $certificate['error'] }}</p>@endif
                @endif
                <div class="an-actions"><x-filament::button size="xs" color="gray" wire:click="checkCertificate">Jetzt prüfen</x-filament::button></div>
            </div>
            <div class="an-tile">
                <h3>Version</h3>
                <p class="an-big">{{ $version['version'] }}</p>
                <p class="an-small">{{ $version['since'] ? 'läuft seit '.$version['since']->setTimezone($tz)->format('d.m.Y H:i') : 'Datum folgt mit dem nächsten Scheduler-Lauf' }} · <a href="https://github.com/roberteinsle/openwehr/releases" target="_blank" rel="noopener" class="underline">Releases</a></p>
            </div>
        </div>

        @if($appErrors['latest']->isNotEmpty())
            <h4 class="mt-4 mb-1 text-sm font-semibold">Letzte Fehler</h4>
            <table class="an-table">
                <thead><tr><th>Zuletzt</th><th>Fehler</th><th>Ort</th><th class="n">Anzahl</th></tr></thead>
                <tbody>@foreach($appErrors['latest'] as $e)<tr><td>{{ $e->last_at->setTimezone($tz)->format('d.m. H:i') }}</td><td><strong>{{ $e->shortClass() }}</strong>: {{ \Illuminate\Support\Str::limit($e->message, 160) }}<br><span class="an-small">{{ $e->url }}</span></td><td class="an-small">{{ $e->location }}</td><td class="n">{{ $e->count }}</td></tr>@endforeach</tbody>
            </table>
        @endif

        <h4 class="mt-4 mb-1 text-sm font-semibold">Geplante Aufgaben (Cronjobs)</h4>
        @if($runs->isEmpty())
            <p class="an-small">Noch keine Läufe erfasst – der Scheduler läuft im Container mit (schedule:work).</p>
        @else
            <table class="an-table">
                <thead><tr><th>Befehl</th><th>Zuletzt gelaufen</th><th>Letzter Fehler</th><th class="n">Läufe / Fehler</th></tr></thead>
                <tbody>@foreach($runs as $r)<tr><td>{{ $r->command }}</td><td>{{ $r->last_finished_at?->setTimezone($tz)->format('d.m. H:i') ?? '–' }}</td>
                    <td class="{{ $r->last_failed_at && $r->last_failed_at->gt(now()->subDay()) ? 'an-bad' : 'an-small' }}">{{ $r->last_failed_at ? $r->last_failed_at->setTimezone($tz)->format('d.m. H:i').' – '.\Illuminate\Support\Str::limit((string) $r->last_error, 100) : '–' }}</td>
                    <td class="n">{{ $num($r->runs) }} / {{ $num($r->failures) }}</td></tr>@endforeach</tbody>
            </table>
        @endif
        <p class="an-small mt-2">Eine Warteschlange (Queue) gibt es nicht: Mails und Benachrichtigungen gehen sofort raus, Fehler dabei stehen oben unter „Fehler“ und „Mailversand“.</p>
    </x-filament::section>

    {{-- 2. Sicherheit --}}
    <x-filament::section heading="Sicherheit und Nachvollziehbarkeit" description="Anmeldungen, Rechte und die letzten Änderungen (30 Tage).">
        <div class="an-grid">
            <div class="an-tile"><h3>Anmeldungen</h3><p class="an-big an-ok">{{ $num($security['logins']) }}</p><p class="an-small">erfolgreich · {{ $num($security['links']) }} Login-Links angefordert</p></div>
            <div class="an-tile"><h3>Fehlgeschlagen</h3><p class="an-big {{ $security['failed'] ? 'an-warn' : '' }}">{{ $num($security['failed']) }}</p><p class="an-small">ungültige, abgelaufene oder schon benutzte Links, gesperrte Konten</p></div>
            <div class="an-tile"><h3>Sperrungen</h3><p class="an-big {{ $security['throttled'] ? 'an-warn' : '' }}">{{ $num($security['throttled']) }}</p><p class="an-small">zu viele Login-Links (5 je Adresse, 20 je IP in der Stunde)</p></div>
            <div class="an-tile"><h3>Konten</h3><p class="an-big">{{ $num($security['mail_changes']) }}</p><p class="an-small">geänderte E-Mail-Adressen · Passwörter gibt es nicht (Anmeldung per Link)</p></div>
        </div>
        @if($security['latest_failed']->isNotEmpty())
            <h4 class="mt-4 mb-1 text-sm font-semibold">Letzte fehlgeschlagene oder gesperrte Anmeldungen</h4>
            <table class="an-table"><tbody>@foreach($security['latest_failed'] as $l)<tr><td>{{ $l->created_at->setTimezone($tz)->format('d.m. H:i') }}</td><td>{{ \App\Models\ActivityLog::EVENTS[$l->event] ?? $l->event }}</td><td>{{ $l->subject_label }}</td><td class="an-small">{{ $l->ip }}</td></tr>@endforeach</tbody></table>
        @endif
        <h4 class="mt-4 mb-1 text-sm font-semibold">Rechte- und Rollenänderungen</h4>
        @if($security['rights']->isEmpty())
            <p class="an-small">Keine in den letzten 30 Tagen.</p>
        @else
            <table class="an-table"><tbody>@foreach($security['rights'] as $l)<tr><td>{{ $l->created_at->setTimezone($tz)->format('d.m. H:i') }}</td><td>{{ $l->user?->name ?? 'System' }}</td><td>{{ \App\Models\ActivityLog::EVENTS[$l->event] ?? $l->event }}: {{ $l->subject_label }}</td><td class="an-small">{{ collect($l->changes ?? [])->keys()->implode(', ') }}</td></tr>@endforeach</tbody></table>
        @endif
        <h4 class="mt-4 mb-1 text-sm font-semibold">Audit-Log – die letzten 5 Einträge</h4>
        <table class="an-table"><tbody>@forelse($audit as $l)<tr><td>{{ $l->created_at->setTimezone($tz)->format('d.m. H:i') }}</td><td>{{ $l->user?->name ?? 'System' }}</td><td>{{ \App\Models\ActivityLog::EVENTS[$l->event] ?? $l->event }}</td><td>{{ \App\Models\ActivityLog::typeLabel($l->subject_type) }}{{ $l->subject_label ? ': '.$l->subject_label : '' }}</td></tr>@empty<tr><td>Noch keine Einträge.</td></tr>@endforelse</tbody></table>
        <p class="mt-2 text-sm"><a href="{{ $logsUrl }}" class="underline">Alle Einträge unter Logs →</a></p>
    </x-filament::section>

    {{-- 3. Nutzung --}}
    <x-filament::section heading="Nutzung" description="Je Modul zusammengezählt, nicht je Person – nur die letzte Anmeldung steht je Kamerad (wie in der Kameradenliste).">
        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <h4 class="mb-1 text-sm font-semibold">Nutzung je Modul</h4>
                <table class="an-table">
                    <thead><tr><th>Modul</th><th class="n">Aufrufe 30 T.</th><th class="n">90 T.</th><th class="n">Änderungen 90 T.</th></tr></thead>
                    <tbody>@foreach($usage as $m)<tr class="{{ $m['views_90'] + $m['changes_90'] === 0 ? 'an-warn' : '' }}"><td>{{ $m['name'] }}@if($m['views_90'] + $m['changes_90'] === 0) <span class="an-small">– nie genutzt</span>@endif</td><td class="n">{{ $num($m['views_30']) }}</td><td class="n">{{ $num($m['views_90']) }}</td><td class="n">{{ $num($m['changes_90']) }}</td></tr>@endforeach</tbody>
                </table>
                <p class="an-small mt-1">Ein Modul, das keiner nutzt, lässt sich unter Module ausschalten – das macht die App übersichtlicher.</p>
                <h4 class="mt-4 mb-1 text-sm font-semibold">Datensätze</h4>
                <table class="an-table">
                    <thead><tr><th>Tabelle</th><th class="n">Anzahl</th><th class="n">neu (30 T.)</th></tr></thead>
                    <tbody>@foreach($records as $r)<tr><td>{{ $r['table'] }}</td><td class="n">{{ $num($r['rows']) }}</td><td class="n">{{ $r['new_30'] === null ? '–' : '+'.$num($r['new_30']) }}</td></tr>@endforeach</tbody>
                </table>
            </div>
            <div>
                <h4 class="mb-1 text-sm font-semibold">Letzte Anmeldung je Kamerad</h4>
                <table class="an-table">
                    <tbody>@foreach($logins as $u)<tr><td>{{ $u->name }}@unless($u->hasLogin())<span class="an-small"> (ohne Login)</span>@endunless</td>
                        <td class="n {{ ! $u->last_login_at && $u->hasLogin() ? 'an-warn' : ($u->last_login_at && $u->last_login_at->lt(now()->subDays(90)) ? 'an-warn' : '') }}">{{ $u->last_login_at ? $u->last_login_at->setTimezone($tz)->format('d.m.Y') : 'noch nie' }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        </div>
    </x-filament::section>

    {{-- 4. Löschfristen --}}
    <x-filament::section heading="Löschfristen" description="Wie lange die Logs aufbewahrt werden. Ältere Einträge löscht die nächtliche Bereinigung.">
        <form wire:submit="saveRetention" class="grid gap-4 sm:grid-cols-3">
            <label class="text-sm">Technische Logs (Tage)<br><span class="an-small">Fehler, Aufrufzahlen, Erreichbarkeit</span>
                <x-filament::input.wrapper><x-filament::input type="number" wire:model="retention.technical" min="{{ $limits['technical'][0] }}" max="{{ $limits['technical'][1] }}" /></x-filament::input.wrapper></label>
            <label class="text-sm">Audit-Log (Tage)<br><span class="an-small">wer wann was geändert hat, Anmeldungen</span>
                <x-filament::input.wrapper><x-filament::input type="number" wire:model="retention.audit" min="{{ $limits['audit'][0] }}" max="{{ $limits['audit'][1] }}" /></x-filament::input.wrapper></label>
            <label class="text-sm">Mail-Log (Tage)<br><span class="an-small">versendete E-Mails</span>
                <x-filament::input.wrapper><x-filament::input type="number" wire:model="retention.mail" min="{{ $limits['mail'][0] }}" max="{{ $limits['mail'][1] }}" /></x-filament::input.wrapper></label>
            <div class="sm:col-span-3"><x-filament::button type="submit">Speichern</x-filament::button>
                <span class="an-small ml-2">Empfehlung: 90 Tage für technische Logs, das Audit-Log nur so lange wie fachlich nötig. Eigene Fristen haben Verbandbuch (10 Jahre) und Expositionen (40 Jahre).</span></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
