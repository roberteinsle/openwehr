@php($short = fn ($name) => \Modules\Fahrzeugbuchung\src\Livewire\Monitor\StationBoard::shortName($name))
@php($tz = 'Europe/Berlin')
<div wire:poll.60s class="min-h-full p-4 lg:p-6 flex flex-col gap-4 bg-gradient-to-br from-[#0b1433] via-[#131f4a] to-[#2a1640]">

    {{-- Header: clock, date, title --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div
            class="rounded-2xl bg-white/5 border border-white/10 px-6 py-4 text-center"
            x-data="{
                time: '', date: '',
                tick() {
                    const now = new Date();
                    this.time = now.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' });
                    this.date = now.toLocaleDateString('de-DE', { timeZone: 'Europe/Berlin', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                }
            }"
            x-init="tick(); setInterval(() => tick(), 1000)"
        >
            <p class="text-6xl font-bold tabular-nums" x-text="time">{{ $updatedAt->format('H:i') }}</p>
            <p class="mt-1 text-xl text-white/80" x-text="date">{{ $updatedAt->isoFormat('dddd, D. MMMM YYYY') }}</p>
        </div>
        <div class="lg:col-span-2 rounded-2xl bg-white/5 border border-white/10 px-6 py-4 flex items-center gap-5">
            @inject('org', \App\Services\OrganizationSettings::class)
            <img src="{{ $org->logoUrl() ?? asset('images/openwehr-logo.svg') }}" alt="" class="h-16 w-auto brightness-0 invert">
            <div class="flex-1">
                <p class="text-3xl font-bold">Fahrzeugbelegung</p>
                <p class="text-white/60">{{ $org->name() }}</p>
            </div>
            <div class="flex gap-2 text-center">
                <div class="rounded-xl bg-[#3fcf12] text-black px-4 py-2"><p class="text-3xl font-bold">{{ $counts['free'] }}</p><p class="text-xs font-semibold uppercase">frei</p></div>
                <div class="rounded-xl bg-[#ffcc00] text-black px-4 py-2"><p class="text-3xl font-bold">{{ $counts['soon'] }}</p><p class="text-xs font-semibold uppercase">bald</p></div>
                <div class="rounded-xl bg-[#ed1c1c] px-4 py-2"><p class="text-3xl font-bold">{{ $counts['busy'] }}</p><p class="text-xs font-semibold uppercase">belegt</p></div>
            </div>
        </div>
    </div>

    {{-- Vehicle tiles --}}
    <div class="grid gap-4" style="grid-template-columns: repeat({{ max(1, min($vehicles->count(), 4)) }}, minmax(0, 1fr));">
        @foreach($vehicles as $item)
        @php($booking = $item['current'] ?? $item['next'])
        <div @class([
            'rounded-2xl p-5 flex flex-col gap-2 min-h-44',
            'bg-[#3fcf12] text-black' => $item['state'] === 'free',
            'bg-[#ffcc00] text-black' => $item['state'] === 'soon',
            'bg-[#ed1c1c] text-white' => $item['state'] === 'busy',
            'bg-[#ed1c1c] text-white ring-4 ring-white' => $item['state'] === 'alarm',
        ])>
            <div class="flex items-start justify-between gap-2">
                <p class="text-3xl font-bold leading-tight">{{ $item['vehicle']->displayName() }}</p>
                <span class="w-4 h-4 mt-2 rounded-full ring-2 ring-white/70 shrink-0" style="background-color: {{ $item['vehicle']->color }}"></span>
            </div>
            <p class="text-xl font-semibold uppercase tracking-wide">
                @if($item['state'] === 'alarm') Im Einsatz
                @elseif($item['state'] === 'busy') Unterwegs bis {{ $item['current']->ends_at->setTimezone($tz)->isSameDay(now()->setTimezone($tz)) ? $item['current']->ends_at->setTimezone($tz)->format('H:i') : $item['current']->ends_at->setTimezone($tz)->isoFormat('dd. D.M., HH:mm') }}
                @elseif($item['state'] === 'soon') Ab {{ $item['next']->starts_at->setTimezone($tz)->format('H:i') }} gebucht
                @else Frei
                @endif
            </p>
            @if($item['state'] === 'alarm')
            <p class="mt-auto text-lg font-semibold truncate">Alarm: {{ $item['alarm'] }}</p>
            @elseif($booking)
            <div class="mt-auto text-lg leading-snug">
                <p class="{{ $item['state'] === 'free' ? 'text-black/70 text-base' : '' }}">
                    @if($item['state'] === 'free')
                    Nächste: {{ $booking->starts_at->setTimezone($tz)->isoFormat('dd. D.M., HH:mm') }}
                    @endif
                </p>
                <p class="font-semibold truncate">{{ $booking->purpose }}</p>
                <p class="opacity-80">{{ $short($booking->user->name) }}</p>
            </div>
            @else
            <p class="mt-auto text-base text-black/60">Keine Buchung in den nächsten 7 Tagen</p>
            @endif
        </div>
        @endforeach
    </div>

    {{-- Upcoming bookings --}}
    <div class="flex-1 rounded-2xl bg-white/5 border border-white/10 overflow-hidden">
        <div class="bg-white/10 px-5 py-3 flex items-center justify-between">
            <p class="text-2xl font-bold">Nächste Buchungen</p>
            <p class="text-sm text-white/60">aktualisiert {{ $updatedAt->format('H:i') }} Uhr</p>
        </div>
        <table class="w-full text-lg">
            <thead class="text-left text-sm uppercase tracking-wide text-white/60">
                <tr>
                    <th class="px-5 py-2 font-medium">Wann</th>
                    <th class="px-5 py-2 font-medium">Fahrzeug</th>
                    <th class="px-5 py-2 font-medium">Zweck</th>
                    <th class="px-5 py-2 font-medium">Name</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                @forelse($upcoming as $booking)
                @php($start = $booking->starts_at->setTimezone($tz))
                @php($end = $booking->ends_at->setTimezone($tz))
                <tr @class(['bg-[#ed1c1c]/20' => $booking->starts_at->isPast()])>
                    <td class="px-5 py-2 whitespace-nowrap tabular-nums">
                        {{ $start->isToday() ? 'Heute' : ($start->isTomorrow() ? 'Morgen' : $start->isoFormat('dd. D.M.')) }},
                        {{ $start->format('H:i') }}–{{ $start->isSameDay($end) ? $end->format('H:i') : $end->isoFormat('D.M. HH:mm') }}
                    </td>
                    <td class="px-5 py-2 whitespace-nowrap">
                        <span class="inline-block w-3 h-3 rounded-full mr-2 align-middle" style="background-color: {{ $booking->vehicle->color }}"></span>{{ $booking->vehicle->displayName() }}
                    </td>
                    <td class="px-5 py-2">
                        @if($booking->isPending())<span class="mr-2 px-2 py-0.5 rounded bg-[#ffcc00] text-black text-sm font-semibold">Angefragt</span>@endif
                        {{ $booking->purpose }}
                    </td>
                    <td class="px-5 py-2 whitespace-nowrap">{{ $short($booking->user->name) }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-5 py-6 text-white/60 italic">Keine Buchungen in den nächsten 7 Tagen.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
