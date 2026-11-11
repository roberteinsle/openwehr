<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Teilnehmerliste – {{ $form->title }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; font-size: 11px; color: #111; margin: 1.5cm; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p { margin: 0 0 10px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: left; vertical-align: top; white-space: pre-line; }
        th { background: #f3f4f6; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body onload="window.print()">
    <h1>{{ $form->title }}</h1>
    <p>{{ collect([$form->dateLabel(), $form->location, $registrations->count().' Teilnehmer'])->filter()->implode(' · ') }} – vertraulich, nach der Veranstaltung vernichten.</p>
    <table>
        <thead><tr><th>Name</th>@foreach($form->fields as $f)<th>{{ $f['label'] }}</th>@endforeach<th>Unterschrieben</th></tr></thead>
        <tbody>
            @foreach($registrations as $r)
                <tr><td>{{ $r->user->name }}</td>@foreach($form->fields as $f)<td>{{ $r->answerLabel($f) }}</td>@endforeach<td>{{ $r->signer_name }}, {{ $r->signed_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') }}</td></tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
