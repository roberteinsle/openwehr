@inject('org', \App\Services\OrganizationSettings::class)
<div wire:poll.60s class="h-full min-h-screen p-4 lg:p-6 flex flex-col gap-4 bg-gradient-to-br from-[#0b1433] via-[#131f4a] to-[#2a1640]">
    {{-- Header: Wehr and clock --}}
    <div class="flex items-center gap-5 rounded-2xl bg-white/5 border border-white/10 px-6 py-3">
        <img src="{{ $org->logoUrl() ?? asset('images/openwehr-logo.svg') }}" alt="" class="h-12 w-auto brightness-0 invert">
        <p class="flex-1 min-w-0 truncate text-3xl font-bold">{{ $org->name() }}</p>
        <div class="text-right"
             x-data="{ time: '', date: '', tick() { const now = new Date();
                 this.time = now.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' });
                 this.date = now.toLocaleDateString('de-DE', { timeZone: 'Europe/Berlin', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); } }"
             x-init="tick(); setInterval(() => tick(), 1000)">
            <p class="text-5xl font-bold tabular-nums leading-none" x-text="time">{{ $updatedAt->format('H:i') }}</p>
            <p class="mt-1 text-lg text-white/80" x-text="date">{{ $updatedAt->isoFormat('dddd, D. MMMM YYYY') }}</p>
        </div>
    </div>

    {{-- 1, 2 or 4 fields --}}
    <div @class([
        'flex-1 min-h-0 grid gap-4 grid-cols-1',
        'lg:grid-cols-2' => $count >= 2,
        'lg:grid-rows-2' => $count === 4,
    ])>
        @foreach($fields as $field)
            <section class="min-h-0 overflow-hidden rounded-2xl bg-white/5 border border-white/10 p-5 flex flex-col">
                @if($field['content'])
                    <h2 class="text-2xl font-bold">{{ $field['title'] }}</h2>
                    <div class="mt-3 flex-1 min-h-0 overflow-hidden">{!! $field['content'] instanceof \Illuminate\Contracts\View\View ? $field['content']->render() : $field['content'] !!}</div>
                @else
                    <p class="m-auto text-white/30">Kein Inhalt gewählt</p>
                @endif
            </section>
        @endforeach
    </div>
    <p class="text-right text-xs text-white/40">Stand {{ $updatedAt->format('H:i') }} · aktualisiert sich jede Minute</p>
</div>
