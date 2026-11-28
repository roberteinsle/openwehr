{{-- Wachen-Monitor: state of the vehicles, below it the next bookings in order of time --}}
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
                    'bg-[#ed1c1c] text-white' => in_array($item['state'], ['busy', 'alarm'], true),
                ])>
                    <p class="truncate text-2xl font-bold leading-tight">{{ $item['vehicle']->displayName() }}</p>
                    <p class="text-base font-semibold">
                        @if($item['state'] === 'alarm') im Einsatz
                        @elseif($item['state'] === 'busy') gebucht bis {{ $item['current']->ends_at->setTimezone($tz)->isSameDay(now()->setTimezone($tz)) ? $item['current']->ends_at->setTimezone($tz)->format('H:i') : $item['current']->ends_at->setTimezone($tz)->isoFormat('dd. D.M., HH:mm') }}
                        @elseif($item['state'] === 'soon') ab {{ $item['next']->starts_at->setTimezone($tz)->format('H:i') }} gebucht
                        @else frei
                        @endif
                    </p>
                    @if($item['state'] === 'alarm' && $size !== 'quarter')
                        <p class="truncate text-sm">Alarm: {{ $item['alarm'] }}</p>
                    @elseif($booking && $item['state'] !== 'free' && $size !== 'quarter')
                        <p class="truncate text-sm">{{ $booking->purpose }} · {{ $short($booking->user?->name) }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        @if($rows > 0)
            <div class="min-h-0 overflow-hidden">
                <p class="text-sm font-semibold uppercase tracking-wide text-white/60">Nächste Buchungen</p>
                @if($upcoming->isEmpty())
                    <p class="mt-2 text-lg text-white/50">Keine Buchungen.</p>
                @else
                    {{-- One row per booking, in order of time: Datum, Uhrzeit, Name, Fahrzeug, Grund --}}
                    <table class="mt-2 w-full table-fixed text-lg">
                        <colgroup>
                            <col style="width: 7rem"><col style="width: 7.5rem"><col style="width: 7rem"><col style="width: 8rem"><col>
                        </colgroup>
                        <tbody class="divide-y divide-white/10">
                            @foreach($upcoming->take($rows) as $b)
                                @php($start = $b->starts_at->setTimezone($tz))
                                @php($end = $b->ends_at->setTimezone($tz))
                                <tr>
                                    <td class="truncate py-1 pr-2 font-semibold tabular-nums">{{ $start->isToday() ? 'Heute' : ($start->isTomorrow() ? 'Morgen' : $weekdays[$start->dayOfWeek].' '.$start->format('d.m.')) }}</td>
                                    <td class="truncate py-1 pr-2 tabular-nums">{{ $start->format('H:i') }}–{{ $start->isSameDay($end) ? $end->format('H:i') : $end->isoFormat('D.M. HH:mm') }}</td>
                                    <td class="truncate py-1 pr-2">{{ $short($b->user?->name) }}</td>
                                    <td class="truncate py-1 pr-2 font-semibold"><span class="mr-1 inline-block h-3 w-3 rounded-full align-middle" style="background-color: {{ $b->vehicle->color }}"></span>{{ $b->vehicle->displayName() }}</td>
                                    <td class="truncate py-1 text-white/70">@if($b->isPending())<span class="rounded bg-[#ffcc00] px-1.5 text-sm font-semibold text-black">Angefragt</span> @endif{{ $b->purpose }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    @endif
</div>
