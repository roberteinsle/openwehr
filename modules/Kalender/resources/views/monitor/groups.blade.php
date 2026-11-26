{{-- Wachen-Monitor: next Termine, one column per Abteilung --}}
@if($columns === [])
    <p class="text-xl text-white/60">Keine Termine in nächster Zeit.</p>
@else
    <div class="grid h-full gap-4" style="grid-template-columns: repeat({{ count($columns) }}, minmax(0, 1fr))">
        @foreach($columns as $column)
            <div class="min-w-0">
                @if($column['group'])
                    <p class="inline-block rounded-lg px-3 py-1 text-lg font-bold" style="{{ $column['group']->badgeStyle() }}">{{ $column['group']->name }}</p>
                @else
                    <p class="inline-block rounded-lg bg-white/15 px-3 py-1 text-lg font-bold">Ganze Wehr</p>
                @endif
                @forelse($column['items'] as $row)
                    @php($e = $row['item'])
                    <div class="mt-3 border-l-4 pl-3" style="border-color: {{ \Modules\Kalender\src\Models\EventType::colorOf($e->type) }}">
                        <p @class(['font-semibold tabular-nums', 'text-xl' => $size !== 'quarter', 'text-lg' => $size === 'quarter'])>{{ $when($e) }}</p>
                        <p @class(['font-bold truncate', 'text-2xl' => $size === 'full', 'text-xl' => $size !== 'full'])>{{ $e->title }}</p>
                        <p class="truncate text-white/70">{{ collect([$e->place?->name ?? $e->location, $row['all'] ? ($e->audienceLabel() ?? 'alle') : null])->filter()->implode(' · ') }}</p>
                    </div>
                @empty
                    <p class="mt-3 text-white/50">Keine Termine.</p>
                @endforelse
            </div>
        @endforeach
    </div>
@endif
