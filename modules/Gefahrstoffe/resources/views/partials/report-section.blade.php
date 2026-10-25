{{-- Gefahrstoff at the Einsatzstelle, in the Einsatzbericht and the Lagemeldungen: only when the report names one
     (Art "Gefahrstoff" or the field "Gefahrstoff (Stoff / UN-Nummer)"); a Warntafel written there ("33/1203") is explained --}}
@php($details = (array) ($report->details ?? []))
@if(($details['kind'] ?? null) === 'Gefahrstoff' || trim((string) ($details['hazmat'] ?? '')) !== '')
    @php($panels = \Modules\Gefahrstoffe\src\Support\HazardNumbers::panelsIn($details['hazmat'] ?? null))
    <section class="{{ $class ?? 'mx-auto mt-4 max-w-4xl' }} space-y-2 rounded-2xl border-2 border-orange-300 bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-semibold text-gray-900">Gefahrstoff{{ ($details['hazmat'] ?? '') !== '' ? ': '.$details['hazmat'] : '' }}</p>
            <a href="{{ route('gefahrstoffe.index') }}" class="text-xs text-fw-navy underline">Nachschlagen</a>
        </div>
        @foreach($panels as $panel)
            <div class="flex gap-3 text-sm">
                <span class="w-20 shrink-0 rounded border-2 border-black bg-orange-500 text-center font-mono font-bold leading-tight text-black"><span class="block border-b-2 border-black">{{ $panel['code'] }}</span>{{ $panel['un'] }}</span>
                <div class="text-gray-700">
                    <p>{{ $panel['meaning'] ?? implode(' · ', $panel['digits']) }}</p>
                    @if($panel['water'])<p class="font-semibold text-fw-red">Reagiert gefährlich mit Wasser – Wasser nur im Einverständnis mit Sachverständigen.</p>@endif
                </div>
            </div>
        @endforeach
        @if($panels === [])
            <p class="text-sm text-gray-500">Gefahrenmatrix, GAMS und Gefahrnummern unter „Nachschlagen“ – auch ohne Netz, wenn die Seite einmal geöffnet war.</p>
        @endif
    </section>
@endif
