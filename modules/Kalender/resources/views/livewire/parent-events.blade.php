<div class="space-y-3">
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    @forelse($events as $event)
        @php($r = $event->responses->first())
        <div class="rounded-xl border border-gray-100 p-3" wire:key="pe-{{ $event->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="font-medium text-gray-900">{{ $event->title }}</p>
                    <p class="text-sm text-gray-600">{{ $event->timeLabel() }}</p>
                    @if($event->place)<p class="text-sm text-gray-500">{{ $event->place->label() }}</p>@elseif($event->location)<p class="text-sm text-gray-500">{{ $event->location }}</p>@endif
                </div>
                <span class="rounded px-2 py-0.5 text-xs font-medium" style="{{ $event->colorStyle() }}">{{ $event->typeLabel() }}</span>
            </div>
            @if($event->rsvp)
                <p class="mt-2 text-sm text-gray-700">
                    @if($r?->guardian_status)
                        Eure Rückmeldung: <strong>{{ \Modules\Kalender\src\Models\Response::labelOf($r->guardian_status) }}</strong> ({{ $r->guardian_name }})
                    @elseif($r?->status)
                        Rückmeldung: <strong>{{ $r->label() }}</strong>
                    @else
                        Noch keine Rückmeldung.
                    @endif
                    @if($r?->own_status) · {{ $child->name }} selbst: {{ \Modules\Kalender\src\Models\Response::labelOf($r->own_status) }}@endif
                    @if($r?->mismatch())<span class="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-xs font-semibold text-red-700">passt nicht zusammen</span>@endif
                </p>
                @if($event->rsvp_deadline)
                    <p class="text-xs {{ $event->pastDeadline() && ! $r?->status ? 'text-fw-red' : 'text-gray-500' }}">Bitte bis {{ $event->rsvp_deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr</p>
                @endif
                @if($event->acceptsResponses())
                    <input type="text" wire:model="comments.{{ $event->id }}" maxlength="400" placeholder="Bemerkung (optional)" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        @foreach(['yes' => ['Zusage', 'bg-green-600'], 'maybe' => ['Vielleicht', 'bg-amber-500'], 'no' => ['Absage', 'bg-gray-600']] as $status => [$label, $color])
                            <button type="button" wire:click="respond({{ $event->id }}, '{{ $status }}')"
                                    class="rounded-lg px-3 py-2 text-sm font-semibold text-white {{ $color }} {{ $r?->guardian_status === $status ? 'ring-2 ring-offset-2 ring-gray-900' : 'opacity-80 hover:opacity-100' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-gray-500">Eine Rückmeldung ist nicht mehr möglich.</p>
                @endif
                @error('e'.$event->id)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">In den nächsten sechs Monaten stehen für {{ $child->name }} keine Termine an.</p>
    @endforelse

    <div class="rounded-xl bg-gray-50 p-3 text-sm">
        <p class="font-medium text-gray-900">Im Handy-Kalender abonnieren</p>
        <p class="text-gray-600">Eine Adresse für Google, Apple oder Outlook mit den Terminen all deiner Kinder – Änderungen kommen automatisch.</p>
        @if($feedUrl)
            <input type="text" readonly value="{{ $feedUrl }}" onclick="this.select()" class="mt-2 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-xs">
            <div class="mt-2 flex flex-wrap gap-3">
                <a href="{{ str_replace(['https://', 'http://'], 'webcal://', $feedUrl) }}" class="text-fw-navy underline">Im Kalender öffnen</a>
                <button type="button" wire:click="newFeed" wire:confirm="Neue Adresse erzeugen? Die alte funktioniert dann nicht mehr." class="text-gray-600 underline">Neue Adresse erzeugen</button>
            </div>
        @else
            <button type="button" wire:click="$set('showFeed', true)" class="mt-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700">Abo-Adresse anzeigen</button>
        @endif
    </div>
</div>
