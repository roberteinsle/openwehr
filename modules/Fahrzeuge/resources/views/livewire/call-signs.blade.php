<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Funkrufnamen</h1>
            <p class="text-sm text-gray-500 print:hidden">Alle Fahrzeuge im Dienst mit ihrem Funkrufnamen – zum schnellen Nachschauen. Gepflegt wird der Funkrufname im Fahrzeugprofil.</p>
        </div>
        <div class="flex items-center gap-2 print:hidden">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Suchen" aria-label="Suchen" class="w-40 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red">
            <button type="button" onclick="window.print()" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Drucken</button>
        </div>
    </div>

    @if($rows->isEmpty())
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">{{ $search !== '' ? 'Nichts gefunden.' : 'Noch kein Fahrzeug hat einen Funkrufnamen.' }}</p>
    @else
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm print:border-0 print:shadow-none">
            @foreach($rows as $r)
                @php($v = $r['vehicle'])
                @php($p = $r['profile'])
                <a href="{{ route('fahrzeuge.show', $v) }}" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50" wire:key="cs-{{ $v->id }}">
                    <span class="min-w-0">
                        <span class="block font-mono text-lg font-bold tracking-wide text-gray-900">{{ $p->call_sign }}</span>
                        <span class="block truncate text-sm text-gray-600">{{ $v->name }}{{ $p->vehicle_type && $p->vehicle_type !== $v->name ? ' · '.$p->vehicle_type : '' }}{{ $p->license_plate ? ' · '.$p->license_plate : '' }}{{ $p->crew ? ' · Besatzung '.$p->crew : '' }}</span>
                    </span>
                    <span class="shrink-0 print:hidden">@include('fahrzeuge::partials.state', ['state' => $states[$v->id]])</span>
                </a>
            @endforeach
        </div>
    @endif

    @if($without->isNotEmpty())
        <p class="text-xs text-gray-500 print:hidden">Ohne Funkrufnamen: {{ $without->map(fn ($r) => $r['vehicle']->name)->implode(', ') }}@if($canManage) – im Fahrzeugprofil unter „Bearbeiten“ eintragen.@endif</p>
    @endif
</div>
