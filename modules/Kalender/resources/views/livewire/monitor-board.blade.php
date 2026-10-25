@php($tz = 'Europe/Berlin')
@php($audience = fn ($e) => collect([...$e->groups->pluck('name'), ...$e->functions->pluck('name')])->implode(', ') ?: 'Alle')
@php($dress = fn ($e) => implode(', ', \App\Models\DressCode::names($e->dress_code_ids)))
@php($time = fn ($e) => $e->all_day ? 'ganztägig' : $e->starts_at->copy()->setTimezone($tz)->format('H:i').'–'.$e->ends_at->copy()->setTimezone($tz)->format('H:i'))
<div wire:poll.60s class="min-h-full p-4 lg:p-6 flex flex-col gap-4 bg-gradient-to-br from-[#0b1433] via-[#131f4a] to-[#2a1640]">
    {{-- Header: clock, date, title --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="rounded-2xl bg-white/5 border border-white/10 px-6 py-4 text-center"
             x-data="{ time: '', date: '', tick() { const now = new Date();
                 this.time = now.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' });
                 this.date = now.toLocaleDateString('de-DE', { timeZone: 'Europe/Berlin', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); } }"
             x-init="tick(); setInterval(() => tick(), 1000)">
            <p class="text-6xl font-bold tabular-nums" x-text="time">{{ $updatedAt->format('H:i') }}</p>
            <p class="mt-1 text-xl text-white/80" x-text="date">{{ $updatedAt->isoFormat('dddd, D. MMMM YYYY') }}</p>
        </div>
        <div class="lg:col-span-2 rounded-2xl bg-white/5 border border-white/10 px-6 py-4 flex items-center gap-5">
            @inject('org', \App\Services\OrganizationSettings::class)
            <img src="{{ $org->logoUrl() ?? asset('images/openwehr-logo.svg') }}" alt="" class="h-16 w-auto brightness-0 invert">
            <div class="flex-1">
                <p class="text-3xl font-bold">Termine</p>
                <p class="text-white/60">{{ $org->name() }} · die nächsten {{ \Modules\Kalender\src\Livewire\MonitorBoard::DAYS }} Tage</p>
            </div>
        </div>
    </div>

    {{-- The next Termin, big, with countdown --}}
    @if($next)
        <div class="rounded-2xl border-2 border-white/20 bg-white/10 px-6 py-5">
            <p class="text-sm font-semibold uppercase tracking-wide text-white/60">Nächster Termin</p>
            <div class="mt-1 flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-4xl font-bold"><span class="mr-2 inline-block rounded-lg px-2 py-0.5 align-middle text-lg" style="background-color: {{ \Modules\Kalender\src\Models\EventType::colorOf($next->type) }}">{{ $next->typeLabel() }}</span>{{ $next->title }}</p>
                    <p class="mt-1 text-2xl text-white/85">{{ $next->timeLabel() }}</p>
                    <p class="text-xl text-white/70">{{ collect([$next->place?->name ?? $next->location, $dress($next) ? 'Kleidung: '.$dress($next) : null, 'Für: '.$audience($next), $next->organizer ? 'Ausführend: '.$next->organizer : null])->filter()->implode(' · ') }}</p>
                </div>
                <x-countdown :at="$next->starts_at" :all-day="$next->all_day" class="!px-5 !py-2 !text-3xl !bg-white !text-[#0b1433]" />
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-white/10 bg-white/5 px-6 py-8 text-center text-2xl text-white/70">In den nächsten {{ \Modules\Kalender\src\Livewire\MonitorBoard::DAYS }} Tagen stehen keine Termine an.</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 flex-1">
        {{-- Today --}}
        <div class="rounded-2xl bg-white/5 border border-white/10 p-5">
            <p class="text-2xl font-bold">Heute</p>
            @forelse($today as $e)
                <div class="mt-3 border-l-4 pl-3" style="border-color: {{ \Modules\Kalender\src\Models\EventType::colorOf($e->type) }}">
                    <p class="text-xl font-semibold">{{ $time($e) }} · {{ $e->title }}</p>
                    <p class="text-white/70">{{ collect([$e->place?->name ?? $e->location, $dress($e), $audience($e)])->filter()->implode(' · ') }}</p>
                </div>
            @empty
                <p class="mt-3 text-white/60">Heute keine Termine.</p>
            @endforelse
        </div>
        {{-- The coming days --}}
        <div class="lg:col-span-2 rounded-2xl bg-white/5 border border-white/10 p-5">
            <p class="text-2xl font-bold">Demnächst</p>
            <div class="mt-2 grid grid-cols-1 xl:grid-cols-2 gap-x-6">
                @forelse($days as $day => $events)
                    <div class="mt-2">
                        <p class="text-sm font-semibold uppercase tracking-wide text-white/60">{{ \Illuminate\Support\Carbon::parse($day)->locale('de')->isoFormat('dddd, D. MMMM') }}</p>
                        @foreach($events as $e)
                            <div class="mt-1 border-l-4 pl-3" style="border-color: {{ \Modules\Kalender\src\Models\EventType::colorOf($e->type) }}">
                                <p class="text-lg font-semibold">{{ $time($e) }} · {{ $e->title }}</p>
                                <p class="text-sm text-white/70">{{ collect([$e->typeLabel(), $e->place?->name ?? $e->location, $dress($e), $audience($e)])->filter()->implode(' · ') }}</p>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="text-white/60">Keine weiteren Termine.</p>
                @endforelse
            </div>
        </div>
    </div>
    <p class="text-right text-xs text-white/40">Stand {{ $updatedAt->format('H:i') }} · aktualisiert sich jede Minute</p>
</div>
