{{-- Wachen-Monitor: state of the vehicles and the next bookings --}}
@php($short = fn ($name) => \Modules\Fahrzeugbuchung\src\Livewire\Monitor\StationBoard::shortName($name))
@php($tz = 'Europe/Berlin')
@php($columns = max(1, min($vehicles->count(), $size === 'full' ? 4 : 3)))
<div class="flex h-full flex-col gap-3">
    @if($vehicles->isEmpty())
        <p class="text-xl text-white/60">Keine Fahrzeuge angelegt.</p>
    @else
        <div class="grid gap-3" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr))">
            @foreach($vehicles as $item)
                @php($booking = $item['current'] ?? $item['next'])
                <div @class([
                    'rounded-xl px-4 py-3 min-w-0',
                    'bg-[#3fcf12] text-black' => $item['state'] === 'free',
                    'bg-[#ffcc00] text-black' => $item['state'] === 'soon',
                    'bg-[#ed1c1c] text-white' => $item['state'] === 'busy',
                ])>
                    <p class="truncate text-2xl font-bold leading-tight">{{ $item['vehicle']->displayName() }}</p>
                    <p class="text-lg font-semibold uppercase tracking-wide">
                        @if($item['state'] === 'busy') Bis {{ $item['current']->ends_at->setTimezone($tz)->isSameDay(now()->setTimezone($tz)) ? $item['current']->ends_at->setTimezone($tz)->format('H:i') : $item['current']->ends_at->setTimezone($tz)->isoFormat('dd. D.M., HH:mm') }}
                        @elseif($item['state'] === 'soon') Ab {{ $item['next']->starts_at->setTimezone($tz)->format('H:i') }} gebucht
                        @else Frei
                        @endif
                    </p>
                    @if($booking && $item['state'] !== 'free')
                        <p class="truncate">{{ $booking->purpose }} · {{ $short($booking->user?->name) }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if($upcoming->isNotEmpty())
        <div class="min-h-0">
            <p class="text-sm font-semibold uppercase tracking-wide text-white/60">Nächste Buchungen</p>
            @foreach($upcoming as $booking)
                @php($start = $booking->starts_at->setTimezone($tz))
                @php($end = $booking->ends_at->setTimezone($tz))
                <p class="mt-1 truncate text-lg">
                    <span class="inline-block h-3 w-3 rounded-full align-middle" style="background-color: {{ $booking->vehicle->color }}"></span>
                    <span class="tabular-nums">{{ $start->isToday() ? 'Heute' : ($start->isTomorrow() ? 'Morgen' : $start->isoFormat('dd. D.M.')) }}, {{ $start->format('H:i') }}–{{ $start->isSameDay($end) ? $end->format('H:i') : $end->isoFormat('D.M. HH:mm') }}</span>
                    · {{ $booking->vehicle->displayName() }}
                    · @if($booking->isPending())<span class="rounded bg-[#ffcc00] px-1.5 text-sm font-semibold text-black">Angefragt</span> @endif{{ $booking->purpose }}
                    · {{ $short($booking->user?->name) }}
                </p>
            @endforeach
        </div>
    @endif
</div>
