@php
    $tz = 'Europe/Berlin';
    $bytes = fn (?int $b) => $b === null ? '–' : ($b >= 1073741824 ? number_format($b / 1073741824, 1, ',', '.').' GB' : number_format($b / 1048576, 1, ',', '.').' MB');
    $c = ['ok' => '#15803d', 'warn' => '#b45309', 'bad' => '#b91c1c', 'none' => 'inherit'];
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Betrieb" icon="heroicon-o-chart-bar">
        <x-slot name="afterHeader"><a href="{{ $analyseUrl }}" style="font-size:.875rem;text-decoration:underline">Analyse öffnen</a></x-slot>
        <style>
            .ow-sys { display:grid; gap:.75rem; grid-template-columns: repeat(3, minmax(0, 1fr)); }
            @media (max-width: 767px) { .ow-sys { grid-template-columns: 1fr; } }
            .ow-sys-tile { border:1px solid rgb(0 0 0 / .08); border-radius:.75rem; padding:.75rem .9rem; }
            .dark .ow-sys-tile { border-color: rgb(255 255 255 / .1); }
            .ow-sys-tile h3 { font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; opacity:.65; }
            .ow-sys-big { font-size:1.35rem; font-weight:700; line-height:1.3; }
            .ow-sys-small { font-size:.78rem; opacity:.75; }
        </style>
        <div class="ow-sys">
            {{-- First row: version, certificate, resources --}}
            <div class="ow-sys-tile">
                <h3>Version</h3>
                <p class="ow-sys-big">{{ $version['version'] }}</p>
                <p class="ow-sys-small">{{ $version['since'] ? 'läuft seit '.$version['since']->setTimezone($tz)->format('d.m.Y H:i') : 'Datum folgt mit dem nächsten Scheduler-Lauf' }}</p>
            </div>
            <div class="ow-sys-tile">
                <h3>TLS-Zertifikat</h3>
                @if($certificate && $certificate['valid_to'])
                    @php($tone = $certificate['days'] < \App\Services\SystemStatus::TLS_WARN_DAYS ? 'bad' : ($certificate['days'] < 30 ? 'warn' : 'ok'))
                    <p class="ow-sys-big" style="color:{{ $c[$tone] }}">noch {{ $certificate['days'] }} Tage</p>
                    <p class="ow-sys-small">gültig bis {{ $certificate['valid_to']->setTimezone($tz)->format('d.m.Y') }}</p>
                @else
                    <p class="ow-sys-big" style="color:{{ $c['warn'] }}">{{ $certificate ? 'unbekannt' : 'noch nicht geprüft' }}</p>
                    <p class="ow-sys-small">Prüfung täglich um 7:15 Uhr oder in der Analyse</p>
                @endif
            </div>
            <div class="ow-sys-tile">
                <h3>Ressourcen</h3>
                @if($resources)
                    <p class="ow-sys-big" style="color:{{ ($resources['disk_percent'] ?? 0) >= \App\Services\SystemStatus::DISK_WARN_PERCENT ? $c['bad'] : $c['none'] }}">{{ $resources['disk_percent'] !== null ? $resources['disk_percent'].' % belegt' : '–' }}</p>
                    <p class="ow-sys-small">frei {{ $bytes($resources['disk_free']) }} · Datenbank {{ $bytes($resources['db_bytes']) }}</p>
                @else
                    <p class="ow-sys-small">Nicht lesbar.</p>
                @endif
            </div>
            {{-- Second row: mail, backups, response times --}}
            <div class="ow-sys-tile">
                <h3>Mailversand (30 Tage)</h3>
                <p class="ow-sys-big" style="color:{{ $mail['failed_30'] ? $c['bad'] : $c['ok'] }}">{{ $mail['failed_30'] }} fehlgeschlagen</p>
                <p class="ow-sys-small">verschickt: {{ number_format($mail['sent_7'], 0, ',', '.') }} in 7, {{ number_format($mail['sent_30'], 0, ',', '.') }} in 30 Tagen</p>
            </div>
            <div class="ow-sys-tile">
                <h3>Backups</h3>
                @if($backup)
                    @php($tone = ! $backup['ok'] ? 'bad' : ($backup['hours'] > \App\Services\SystemStatus::BACKUP_MAX_HOURS ? 'warn' : 'ok'))
                    <p class="ow-sys-big" style="color:{{ $c[$tone] }}">{{ $backup['ok'] ? 'vor '.$backup['hours'].' Std.' : 'fehlgeschlagen' }}</p>
                    <p class="ow-sys-small">{{ $backup['at']->setTimezone($tz)->format('d.m.Y H:i') }} · {{ $backup['source'] }}</p>
                @else
                    <p class="ow-sys-big" style="color:{{ $c['warn'] }}">keine Meldung</p>
                    <p class="ow-sys-small">Webhook oder „Heute geprüft“ in der Analyse</p>
                @endif
            </div>
            <div class="ow-sys-tile">
                <h3>Antwortzeiten (7 Tage)</h3>
                <p class="ow-sys-big">{{ $requests['avg_ms'] !== null ? $requests['avg_ms'].' ms' : '–' }}</p>
                <p class="ow-sys-small">langsamste {{ $requests['max_ms'] ?? '–' }} ms · {{ number_format($requests['count'], 0, ',', '.') }} Aufrufe · <span style="color:{{ $requests['server_errors'] ? $c['bad'] : 'inherit' }}">{{ $requests['server_errors'] }} Serverfehler</span></p>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
