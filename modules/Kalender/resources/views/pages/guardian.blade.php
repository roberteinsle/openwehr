<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Rückmeldung für {{ $child->name }}</title>
    <style>
        body { margin: 0; background: #f3f4f6; font-family: ui-sans-serif, system-ui, sans-serif; color: #111827; }
        main { max-width: 30rem; margin: 0 auto; padding: 1.5rem 1rem; }
        .card { background: #fff; border-radius: 1rem; padding: 1.25rem; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        h1 { font-size: 1.2rem; margin: 0 0 .25rem; }
        p { margin: .35rem 0; line-height: 1.45; }
        .muted { color: #6b7280; font-size: .9rem; }
        .ok { background: #ecfdf5; color: #065f46; border-radius: .6rem; padding: .6rem .8rem; }
        .err { background: #fef2f2; color: #991b1b; border-radius: .6rem; padding: .6rem .8rem; }
        .buttons { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; margin-top: .75rem; }
        button { border: 0; border-radius: .6rem; padding: .75rem .5rem; font-size: 1rem; font-weight: 600; color: #fff; }
        textarea { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: .6rem; padding: .5rem; font: inherit; margin-top: .75rem; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <p class="muted">Rückmeldung für {{ $child->name }}</p>
        <h1>{{ $event->typeLabel() }}: {{ $event->title }}</h1>
        <p>{{ $event->timeLabel() }}</p>
        @if($event->place)<p>Ort: {{ $event->place->label() }}</p>@elseif($event->location)<p>Ort: {{ $event->location }}</p>@endif
        @if($event->isCancelled())<p class="err">Dieser Termin ist abgesagt.</p>@endif

        @if(session('kalender-guardian'))<p class="ok">{{ session('kalender-guardian') }}</p>@endif
        @if($error)<p class="err">{{ $error }}</p>@endif
        <p class="muted">Bisher: <strong>{{ $response?->status ? \Modules\Kalender\src\Models\Response::STATUSES[$response->status] : 'keine Rückmeldung' }}</strong>
            @if($response?->guardian_status) (von {{ $response->guardian_name }})@endif
            @if($response?->own_status) · {{ $child->name }} selbst: {{ \Modules\Kalender\src\Models\Response::labelOf($response->own_status) }}@endif</p>
        <p class="muted">Deine Rückmeldung zählt, auch wenn {{ $child->name }} selbst anders geantwortet hat.</p>

        @if($event->acceptsResponses() && ! $event->isCancelled())
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <textarea name="comment" rows="2" maxlength="400" placeholder="Bemerkung (optional)"></textarea>
                <div class="buttons">
                    <button type="submit" name="status" value="yes" style="background:#16a34a">Zusage</button>
                    <button type="submit" name="status" value="maybe" style="background:#d97706">Vielleicht</button>
                    <button type="submit" name="status" value="no" style="background:#dc2626">Absage</button>
                </div>
            </form>
        @else
            <p class="muted">Für diesen Termin ist keine Rückmeldung (mehr) möglich.</p>
        @endif
    </div>
    <p class="muted" style="text-align:center">Du bekommst diese Seite als Erziehungsberechtigte(r) von {{ $child->name }} ({{ $guardian->name }}).
        Alle Termine, das Kalender-Abo und mehr findest du im <a href="{{ route('parents.index') }}">Eltern-Bereich</a> – Anmeldung mit deiner E-Mail-Adresse.</p>
</main>
</body>
</html>
