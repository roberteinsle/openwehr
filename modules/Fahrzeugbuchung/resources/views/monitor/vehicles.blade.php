{{-- Wachen-Monitor: state of the vehicles, below it the next bookings of each vehicle --}}
@php($short = fn ($name) => \Modules\Fahrzeugbuchung\src\Livewire\Monitor\StationBoard::shortName($name))
@php($tz = 'Europe/Berlin')
@php($columns = max(1, min($vehicles->count(), ['full' => 4, 'half' => 3, 'third' => 2, 'quarter' => 3][$size] ?? 3)))
@php($weekdays = ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'])
<div class="flex h-full flex-col gap-4">
    @if($vehicles->isEmpty())
        <p class="text-xl text-white/60">Keine Fahrzeuge angelegt.</p>
    @else
        <div class="grid gap-3" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr))">
            @foreach($vehicles as $item)
                @php($booking = $item['current'] ?? $item['next'])
                <div @class([
                    'rounded-xl px-4 py-2 min-w-0 text-center',
                    'bg-[#3fcf12] text-black' => $item['state'] === 'free',
                    'bg-[#ffcc00] text-black' => $item['state'] === 'soon',
                    'bg-[#ed1c1c] text-white' => $item['state'] === 'busy',
                ])>
                    <p class="truncate text-2xl font-bold leading-tight">{{ $item['vehicle']->displayName() }}</p>
                    <p class="text-base font-semibold">
                        @if($item['state'] === 'busy') gebucht bis {{ $item['current']->ends_at->setTimezone($tz)->isSameDay(now()->setTimezone($tz)) ? $item['current']->ends_at->setTimezone($tz)->format('H:i') : $item['current']->ends_at->setTimezone($tz)->isoFormat('dd. D.M., HH:mm') }}
                        @elseif($item['state'] === 'soon') ab {{ $item['next']->starts_at->setTimezone($tz)->format('H:i') }} gebucht
                        @else frei
                        @endif
                    </p>
                    @if($booking && $item['state'] !== 'free' && $size !== 'quarter')
                        <p class="truncate text-sm">{{ $booking->purpose }} · {{ $short($booking->user?->name) }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        @if($perVehicle > 0)
            <div class="min-h-0 overflow-hidden">
                <p class="text-sm font-semibold uppercase tracking-wide text-white/60">Nächste Buchungen</p>
                <div class="mt-2 divide-y divide-white/10 rounded-lg border border-white/10">
                    @foreach($vehicles as $item)
                        @php($all = $upcoming->where('vehicle_id', $item['vehicle']->id))
                        @php($own = $all->take($perVehicle))
                        <div class="flex gap-3 px-3 py-2">
                            <p class="flex w-32 shrink-0 items-start gap-2 text-lg font-bold">
                                <span class="mt-2 inline-block h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $item['vehicle']->color }}"></span>
                                <span class="min-w-0 truncate">{{ $item['vehicle']->displayName() }}</span>
                            </p>
                            <div class="min-w-0 flex-1 space-y-1.5">
                                @forelse($own as $b)
                                    @php($start = $b->starts_at->setTimezone($tz))
                                    @php($end = $b->ends_at->setTimezone($tz))
                                    @php($day = $start->isToday() ? 'Heute' : ($start->isTomorrow() ? 'Morgen' : $weekdays[$start->dayOfWeek].', '.$start->format('d.m.')))
                                    <div class="min-w-0">
                                        <p class="flex items-baseline justify-between gap-3 text-lg">
                                            <span class="shrink-0 font-semibold tabular-nums">{{ $day }}, {{ $start->format('H:i') }}–{{ $start->isSameDay($end) ? $end->format('H:i') : $end->isoFormat('D.M. HH:mm') }}</span>
                                            <span class="min-w-0 truncate text-right">@if($b->isPending())<span class="rounded bg-[#ffcc00] px-1.5 text-sm font-semibold text-black">Angefragt</span> @endif{{ $short($b->user?->name) }}</span>
                                        </p>
                                        @if(filled($b->purpose))
                                            <p class="truncate text-base text-white/70">{{ $b->purpose }}</p>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-lg text-white/50">keine Buchung</p>
                                @endforelse
                                @if($all->count() > $own->count())
                                    <p class="text-sm text-white/60">+ {{ $all->count() - $own->count() }} weitere</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
