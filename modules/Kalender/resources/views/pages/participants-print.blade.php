@inject('org', \App\Services\OrganizationSettings::class)
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Teilnehmer – {{ $event->title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 1.5rem; font-size: .9rem; }
        h1 { font-size: 1.3rem; margin: 0; }
        .meta { color: #4b5563; margin: .25rem 0 1rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: .35rem .5rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        td.sign { width: 9rem; }
        .bar { margin-bottom: 1rem; }
        .bar button { padding: .5rem 1rem; border-radius: .5rem; border: 0; background: #ED2F12; color: #fff; font-weight: 600; cursor: pointer; }
        @page { size: A4; margin: 1.2cm; }
        @media print { .bar { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" onclick="window.print()">Drucken / als PDF speichern</button></div>
    <h1>{{ $event->typeLabel() }}: {{ $event->title }}</h1>
    <p class="meta">{{ $org->name() }} · {{ $event->timeLabel() }}{{ $event->location ? ' · '.$event->location : '' }} · {{ $summary['yes']->count() }} Zusagen, {{ $summary['maybe']->count() }} vielleicht, {{ $summary['no']->count() }} Absagen, {{ $summary['open']->count() }} offen</p>
    <table>
        <tr><th>Name</th><th>Abteilungen</th><th>Rückmeldung</th><th>Kommentar</th><th>Unterschrift / anwesend</th></tr>
        @foreach($people as $row)
            <tr><td>{{ $row['user']->name }}</td><td>{{ $row['groups'] }}</td><td>{{ $row['status'] }}@if($row['guardian'])<br><small>Eltern: {{ $row['guardian'] }}@if($row['own']) · Kind: {{ $row['own'] }}@endif</small>@endif @if($row['mismatch'])<br><strong>passt nicht zusammen</strong>@endif</td><td>{{ $row['comment'] }}</td><td class="sign">{{ $row['attended'] }}</td></tr>
        @endforeach
    </table>
</body>
</html>
