@inject('org', \App\Services\OrganizationSettings::class)
@php($types = \Modules\Kalender\src\Models\Event::TYPES)
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $heading }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; margin: 1.5rem; font-size: .8rem; }
        .head { display: flex; align-items: center; gap: 1rem; border-bottom: 2px solid #111827; padding-bottom: .5rem; margin-bottom: .4rem; }
        .head img { height: 3rem; width: auto; }
        h1 { font-size: 1.5rem; margin: 0; text-decoration: underline; }
        h2 { font-size: 1.05rem; margin: .1rem 0 .7rem; text-align: center; text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #6b7280; padding: .18rem .35rem; text-align: left; vertical-align: top; }
        th { background: #d1d5db; }
        td.date { white-space: nowrap; font-weight: 600; }
        .note { margin-top: .8rem; text-align: center; white-space: pre-line; }
        .note strong { color: #dc2626; font-size: 1.05rem; }
        .legend { margin-top: .5rem; color: #4b5563; font-size: .72rem; }
        .legend span { margin-right: .8rem; }
        .contacts { margin: .8rem auto 0; border: 0; width: auto; }
        .contacts td { border: 0; padding: .05rem .8rem; }
        .stand { margin-top: .6rem; color: #6b7280; font-size: .7rem; text-align: center; }
        form.bar { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: flex-end; margin-bottom: 1.2rem; padding: .8rem; background: #f3f4f6; border-radius: .6rem; font-size: .85rem; }
        form.bar label { display: flex; flex-direction: column; gap: .2rem; }
        form.bar .types { display: flex; flex-wrap: wrap; gap: .2rem .7rem; flex-direction: row; }
        form.bar .types label { flex-direction: row; align-items: center; gap: .3rem; }
        form.bar input[type=text], form.bar select, form.bar textarea { padding: .3rem .4rem; border: 1px solid #9ca3af; border-radius: .4rem; font: inherit; }
        form.bar button { padding: .45rem .9rem; border-radius: .5rem; border: 0; background: #1e3a5f; color: #fff; font-weight: 600; cursor: pointer; }
        form.bar button.print { background: #ED2F12; }
        @page { size: A4; margin: 1cm; }
        @media print { form.bar, .back { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <p class="back"><a href="{{ route('kalender.index') }}">← Kalender</a></p>
    <form class="bar" method="get" action="{{ route('kalender.roster') }}">
        <label>Jahr<select name="jahr">@foreach($years as $y)<option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>@endforeach</select></label>
        <label>Zeitraum<select name="zeitraum">@foreach(\Modules\Kalender\src\Support\Roster::PERIODS as $key => $label)<option value="{{ $key }}" @selected($key === $period)>{{ $label }}</option>@endforeach</select></label>
        <label>Für<select name="abteilung"><option value="">Alle Abteilungen</option>@foreach($groups as $id => $name)<option value="{{ $id }}" @selected($id === $groupId)>{{ $name }}</option>@endforeach</select></label>
        <label>Überschrift<input type="text" name="titel" value="{{ $heading }}" maxlength="120" size="34"></label>
        <label>Unterzeile<input type="text" name="unterzeile" value="{{ $subheading }}" maxlength="120" size="22"></label>
        <div class="types"><span>Arten:</span>@foreach($types as $key => $label)<label><input type="checkbox" name="arten[]" value="{{ $key }}" @checked(in_array($key, $selectedTypes, true))> {{ $label }}</label>@endforeach</div>
        <label style="flex-basis: 100%">Hinweis unter der Tabelle (optional, erste Zeile fett und rot)<textarea name="hinweis" rows="2" maxlength="600">{{ $hint }}</textarea></label>
        <label style="flex-direction: row; align-items: center; gap: .3rem"><input type="checkbox" name="kontakte" value="1" @checked($showContacts)> Ansprechpartner anzeigen</label>
        <button type="submit">Übernehmen</button>
        <button type="button" class="print" onclick="window.print()">Drucken / als PDF speichern</button>
    </form>

    <div class="head">
        @if($logo)<img src="{{ $logo }}" alt="">@endif
        <div><h1>{{ $heading }}</h1></div>
    </div>
    @if($subheading)<h2>{{ $subheading }}</h2>@endif

    <table>
        <thead><tr><th>Datum</th><th>Zeit</th><th>Dienst</th><th>Ort</th><th>Anzug</th><th>Teilnehmer</th><th>Ausführend</th></tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr style="color: {{ $row['color'] }}">
                    <td class="date">{{ $row['date'] }}</td><td>{{ $row['time'] }}</td><td>{{ $row['title'] }}</td><td>{{ $row['location'] }}</td>
                    <td>{{ $row['dress'] }}</td><td>{{ $row['audience'] }}</td><td>{{ $row['organizer'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7">Keine Termine in diesem Zeitraum.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="legend">@foreach($types as $key => $label)@if(in_array($key, $selectedTypes, true))<span style="color: {{ \Modules\Kalender\src\Support\Roster::COLORS[$key] ?? '#111827' }}">■ {{ $label }}</span>@endif @endforeach</p>

    @if($hint !== '')
        @php($lines = preg_split('/\R/', $hint, 2))
        <p class="note"><strong>{{ $lines[0] }}</strong>@if(isset($lines[1]))<br>{{ $lines[1] }}@endif</p>
    @endif
    @if($showContacts && $contacts->isNotEmpty())
        <table class="contacts">
            @foreach($contacts as $c)<tr><td>{{ $c->role }}:</td><td>{{ $c->name }}</td><td>{{ $c->phone ? 'Tel.: '.$c->phone : '' }}</td></tr>@endforeach
        </table>
    @endif
    <p class="stand">{{ $org->name() }} · Stand {{ now('Europe/Berlin')->format('d.m.Y') }} · Änderungen vorbehalten – aktuell immer im Kalender der App.</p>
</body>
</html>
