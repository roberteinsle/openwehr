{{-- Wachen-Monitor: the next Termine of all Abteilungen --}}
@forelse($events as $e)
    <div class="mt-2 first:mt-0 border-l-4 pl-3" style="border-color: {{ \Modules\Kalender\src\Models\EventType::colorOf($e->type) }}">
        <p @class(['font-semibold truncate', 'text-xl' => $size !== 'quarter', 'text-lg' => $size === 'quarter'])>{{ $when($e) }} · {{ $e->title }}</p>
        <p class="truncate text-white/70">{{ collect([$e->typeLabel(), $e->place?->name ?? $e->location, $e->audienceLabel() ?? 'Alle'])->filter()->implode(' · ') }}</p>
    </div>
@empty
    <p class="text-xl text-white/60">Keine Termine in nächster Zeit.</p>
@endforelse
