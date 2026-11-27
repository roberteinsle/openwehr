{{-- Wachen-Monitor: the coming days row by row with their Termine, below them the next Termine --}}
@php($weekdays = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'])
<div class="flex h-full flex-col gap-4">
    <div class="min-h-0 overflow-hidden rounded-lg border border-white/10">
        @foreach($days as $day)
            @php($shown = $day['events']->take($perDay))
            @php($more = $day['events']->count() - $shown->count())
            <div @class([
                'flex items-stretch gap-2 border-b border-white/10 last:border-b-0 px-2',
                'bg-white/20' => $day['weekend'] && ! $day['today'],
                'bg-white/5' => ! $day['weekend'] && ! $day['today'],
                'bg-[#ed1c1c]/30' => $day['today'],
                'py-1 text-lg' => $size !== 'quarter',
                'py-0.5 text-base' => $size === 'quarter',
            ])>
                <span class="w-14 shrink-0 font-semibold tabular-nums">{{ $day['date']->format('d.m.') }}</span>
                <span @class(['w-8 shrink-0', 'font-bold' => $day['weekend'], 'text-white/70' => ! $day['weekend']])>{{ $weekdays[$day['date']->dayOfWeek] }}</span>
                <div class="grid min-w-0 flex-1 gap-2" style="grid-template-columns: repeat({{ $perDay }}, minmax(0, 1fr))">
                    @foreach($shown as $e)
                        <span class="min-w-0 truncate border-l-4 pl-2 font-semibold" style="border-color: {{ \Modules\Kalender\src\Models\EventType::colorOf($e->type) }}"
                              title="{{ $e->title }}">{{ $e->title }}@if($e->groups->isNotEmpty())<span class="font-normal text-white/60"> · {{ $e->groups->pluck('name')->implode(', ') }}</span>@endif</span>
                    @endforeach
                </div>
                @if($more > 0)
                    <span class="shrink-0 text-white/70">+{{ $more }}</span>
                @endif
            </div>
        @endforeach
    </div>

    @if($next->isNotEmpty())
        <div class="min-h-0">
            <p class="text-sm font-semibold uppercase tracking-wide text-white/60">Nächste Termine</p>
            @foreach($next as $e)
                <p @class(['mt-1 truncate', 'text-lg' => $size !== 'quarter'])>
                    <span class="font-semibold tabular-nums">{{ $when($e) }}</span> · {{ $e->title }}
                </p>
            @endforeach
        </div>
    @endif
</div>
