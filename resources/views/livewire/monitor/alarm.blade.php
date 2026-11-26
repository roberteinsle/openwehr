@inject('org', \App\Services\OrganizationSettings::class)
@php
    // What the admin chose to show (Admin → DIVERA-Webhook); the map takes at most half the screen
    $withMap = isset($show['map']) && $alarm->hasPosition();
    $lists = collect([
        'ric' => ['RIC', $alarm->rics],
        'vehicle' => ['Fahrzeuge', $alarm->vehicles],
        'person' => ['Personen', $alarm->persons],
    ])->filter(fn ($row, $key) => isset($show[$key]) && ! empty($row[1]));
    $texts = collect([
        'keyword' => ['Stichwort', $alarm->keyword ?: 'Alarm'],
        'address' => ['Einsatzort', $alarm->address],
        'message' => ['Meldung', $alarm->message],
    ])->filter(fn ($row, $key) => isset($show[$key]) && filled($row[1]));
@endphp
{{-- Alarm view of the Wachen-Monitor: takes the whole screen while a DIVERA alarm runs --}}
<div wire:poll.{{ $refresh * 60 }}s data-alarm-state="{{ $state }}" class="h-full min-h-screen p-4 lg:p-6 flex flex-col gap-4 bg-[#2a0707]">
    <div class="flex items-center gap-5 rounded-2xl bg-fw-red px-6 py-4 shadow-lg {{ $alarm->isTest() ? '' : 'animate-pulse' }}">
        <p class="text-5xl font-black tracking-widest">{{ $alarm->isTest() ? 'TEST-ALARM' : 'ALARM' }}</p>
        @if($alarm->priority)<span class="rounded-lg bg-white px-3 py-1 text-xl font-bold text-fw-red">Sonderrechte</span>@endif
        <p class="flex-1 min-w-0 truncate text-right text-2xl font-semibold text-white/90">{{ $org->name() }}</p>
        <div class="text-right"
             x-data="{ time: '', since: '', tick() { const now = new Date();
                 this.time = now.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' });
                 const m = Math.max(0, Math.floor((now.getTime() - {{ $alarm->received_at->getTimestampMs() }}) / 60000));
                 this.since = m < 60 ? 'seit ' + m + ' Min.' : 'seit ' + Math.floor(m / 60) + ' Std. ' + (m % 60) + ' Min.'; } }"
             x-init="tick(); setInterval(() => tick(), 1000)">
            <p class="text-5xl font-bold tabular-nums leading-none" x-text="time">{{ now('Europe/Berlin')->format('H:i') }}</p>
            <p class="mt-1 text-lg text-white/90" x-text="since">seit {{ \App\Services\AlarmService::age($alarm->received_at) }}</p>
        </div>
    </div>

    <div class="flex-1 min-h-0 grid gap-4 grid-cols-1 {{ $withMap ? 'lg:grid-cols-2' : '' }}">
        <section class="min-h-0 overflow-hidden rounded-2xl bg-white/5 border border-white/10 p-6 flex flex-col gap-5">
            {{-- Stichwort, Einsatzort and Meldung in the same size --}}
            @foreach($texts as $key => [$label, $text])
                <div @class(['min-h-0 overflow-hidden' => $key === 'message'])>
                    <p class="text-sm uppercase tracking-wide text-white/50">{{ $label }}</p>
                    <p class="text-4xl font-bold leading-tight break-words {{ $key === 'message' ? 'whitespace-pre-line' : '' }}">{{ $text }}</p>
                </div>
            @endforeach
            @foreach($lists as $key => [$label, $names])
                <div>
                    <p class="text-sm uppercase tracking-wide text-white/50">{{ $label }}</p>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach($names as $name)<span class="rounded-lg bg-white/10 border border-white/20 px-3 py-1 text-2xl font-semibold">{{ $name }}</span>@endforeach
                    </div>
                </div>
            @endforeach
            <p class="mt-auto text-sm text-white/50">Alarmiert {{ $alarm->received_at->copy()->timezone('Europe/Berlin')->format('d.m.Y, H:i') }} Uhr</p>
            @if($others->isNotEmpty())
                <div class="rounded-xl bg-fw-red/20 border border-fw-red/40 p-3">
                    <p class="text-sm font-semibold">Weitere Alarme</p>
                    @foreach($others as $other)
                        <p class="text-lg truncate">{{ $other->received_at->copy()->timezone('Europe/Berlin')->format('H:i') }} · {{ $other->keyword ?: 'Alarm' }}@if($other->address) · {{ $other->address }}@endif</p>
                    @endforeach
                </div>
            @endif
        </section>

        @if($withMap)
            <section class="min-h-[50vh] lg:min-h-0 overflow-hidden rounded-2xl border border-white/10" style="isolation:isolate"
                     wire:ignore wire:key="alarm-map-{{ $alarm->id }}-{{ $alarm->latitude }}-{{ $alarm->longitude }}"
                     x-data="alarmMap({ lat: {{ $alarm->latitude }}, lng: {{ $alarm->longitude }} })">
                <div x-ref="map" class="h-full w-full min-h-[50vh]"></div>
            </section>
        @endif
    </div>
</div>
