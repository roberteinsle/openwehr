@php($data = \Modules\Gefahrstoffe\src\Support\HazardNumbers::data())
@php($hazards = \Modules\Gefahrstoffe\src\Support\HazardNumbers::HAZARDS)
@php($targets = \Modules\Gefahrstoffe\src\Support\HazardNumbers::TARGETS)
<x-layouts.app title="Gefahrstoffe">
    {{-- Everything is in the page and searched in the browser, so the cached copy works without network --}}
    <div class="mx-auto max-w-3xl space-y-4" x-data="hazardLookup(@js(['digits' => $data['digits'], 'numbers' => $data['numbers']]))">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Gefahrstoffe</h1>
            <p class="text-sm text-gray-500">Warntafel lesen und Gefahren an der Einsatzstelle erkennen. Einmal geöffnet, ist diese Seite auf dem Gerät auch ohne Netz da.</p>
        </div>

        <section class="space-y-3 rounded-2xl border-2 border-orange-300 bg-white p-4 shadow-sm">
            <h2 class="font-semibold text-gray-900">Warntafel</h2>
            <div class="flex flex-wrap items-start gap-4">
                <div class="w-36 shrink-0 overflow-hidden rounded border-4 border-black bg-orange-500 text-center font-mono text-2xl font-bold text-black" aria-hidden="true">
                    <div class="border-b-4 border-black py-1" x-text="result?.code || '33'"></div>
                    <div class="py-1">1203</div>
                </div>
                <div class="min-w-0 flex-1 space-y-2 text-sm text-gray-600">
                    <p><b>Oben</b> die Gefahrnummer (Nummer zur Kennzeichnung der Gefahr), <b>unten</b> die UN-Nummer des Stoffes. Den Stoff zur UN-Nummer liefern Leitstelle, Beförderungspapier oder schriftliche Weisungen im Fahrzeug.</p>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Gefahrnummer (obere Zahl)</span>
                        <input type="text" x-model="code" inputmode="text" autocomplete="off" maxlength="5" placeholder="z. B. 33 oder X423" class="mt-1 w-full rounded-xl border-gray-300 font-mono text-lg uppercase" aria-describedby="hazard-result">
                    </label>
                </div>
            </div>
            <div id="hazard-result" aria-live="polite">
                <template x-if="result">
                    <div class="space-y-2 rounded-xl bg-orange-50 p-3">
                        <p class="font-semibold text-gray-900"><span class="font-mono" x-text="result.code"></span>: <span x-text="result.meaning || 'nicht in der Liste des ADR – Bedeutung aus den Ziffern:'"></span></p>
                        <p x-show="result.water" class="rounded-lg bg-fw-red px-3 py-2 text-sm font-semibold text-white">X: reagiert gefährlich mit Wasser – Wasser nur im Einverständnis mit Sachverständigen.</p>
                        <ul class="list-disc pl-5 text-sm text-gray-700"><template x-for="d in result.digits" :key="d"><li x-text="d"></li></template></ul>
                        <p x-show="result.doubled" class="text-xs text-gray-500">Eine doppelte Ziffer bedeutet eine größere Gefahr.</p>
                    </div>
                </template>
                <p x-show="code.trim() !== '' && !result" class="text-sm text-gray-500">Eine Gefahrnummer hat zwei oder drei Ziffern, manchmal mit X davor.</p>
            </div>
        </section>

        <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" x-data="{ marks: {} }">
            <div class="flex items-center justify-between gap-2">
                <h2 class="font-semibold text-gray-900">Gefahrenmatrix (AAAA-C-EEEE)</h2>
                <button type="button" class="text-xs text-fw-navy underline" @click="marks = {}">Zurücksetzen</button>
            </div>
            <p class="text-sm text-gray-500">Für die Lagebeurteilung: Welche Gefahr bedroht wen? Antippen markiert ein Feld (nur auf diesem Gerät, wird nicht gespeichert).</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[34rem] text-sm">
                    <thead><tr class="text-xs text-gray-500"><th class="p-1 text-left">Gefahr</th>@foreach($targets as $t)<th class="p-1">{{ $t }}</th>@endforeach</tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($hazards as $key => [$label, $hint])
                            <tr>
                                <th class="p-1 text-left font-normal"><span class="font-semibold text-gray-900">{{ $label }}</span><span class="block text-xs text-gray-500">{{ $hint }}</span></th>
                                @foreach($targets as $i => $t)
                                    <td class="p-1 text-center">
                                        <button type="button" class="h-9 w-9 rounded-lg border text-base font-bold" :class="marks['{{ $key }}-{{ $i }}'] ? 'border-fw-red bg-fw-red text-white' : 'border-gray-200 text-gray-300'"
                                            @click="marks['{{ $key }}-{{ $i }}'] = !marks['{{ $key }}-{{ $i }}']" :aria-pressed="!!marks['{{ $key }}-{{ $i }}']" aria-label="{{ $label }} – {{ $t }}">!</button>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <h2 class="font-semibold text-gray-900">Erste Maßnahmen: GAMS</h2>
            <ul class="space-y-1 text-sm text-gray-700">
                <li><b>G</b>efahr erkennen – Warntafel, Gefahrzettel, Geruch, Dampfwolke; Abstand halten, mit dem Wind anfahren.</li>
                <li><b>A</b>bsperren – Gefahrenbereich festlegen und räumen, niemanden hineinlassen.</li>
                <li><b>M</b>enschenrettung – nur mit passender Schutzausrüstung.</li>
                <li><b>S</b>pezialkräfte nachfordern – Gefahrgutzug, Fachberatung, Messtechnik.</li>
            </ul>
        </section>

        <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <h2 class="font-semibold text-gray-900">Die Ziffern</h2>
            <dl class="grid gap-1 text-sm sm:grid-cols-2">
                @foreach($data['digits'] as $digit => $meaning)
                    <div class="flex gap-2"><dt class="w-5 shrink-0 font-mono font-bold">{{ $digit }}</dt><dd class="text-gray-700">{{ $meaning }}</dd></div>
                @endforeach
            </dl>
            <p class="text-xs text-gray-500">Eine doppelte Ziffer steht für eine größere Gefahr; reicht eine Ziffer, folgt eine 0 (z. B. 30). Ein X davor: reagiert gefährlich mit Wasser.</p>
        </section>

        <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" x-data="{ filter: '' }">
            <h2 class="font-semibold text-gray-900">Alle Gefahrnummern</h2>
            <input type="search" x-model="filter" placeholder="Suchen, z. B. giftig oder 26" class="w-full rounded-xl border-gray-300 text-sm" aria-label="Gefahrnummern durchsuchen">
            <div class="divide-y divide-gray-100 text-sm">
                @foreach($data['numbers'] as $number => $meaning)
                    <div class="flex gap-3 py-1.5" x-show="filter === '' || @js(mb_strtolower($number.' '.$meaning)).includes(filter.toLowerCase().trim())">
                        <span class="w-12 shrink-0 font-mono font-bold {{ str_starts_with($number, 'X') ? 'text-fw-red' : 'text-gray-900' }}">{{ $number }}</span>
                        <span class="text-gray-700">{{ $meaning }}</span>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-500">Quelle: {{ $data['edition'] }}, Unterabschnitt 5.3.2.3, in der im Bundesgesetzblatt verkündeten Fassung. Für die Klasse 1 (Explosivstoffe) steht statt der Gefahrnummer der Klassifizierungscode auf der Tafel.</p>
        </section>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            window.Alpine.data('hazardLookup', ({ digits, numbers }) => ({
                code: '',
                get result() {
                    const code = this.code.replace(/\s+/g, '').toUpperCase();
                    if (!/^X?[2-9][0-9]{1,2}$/.test(code)) {
                        return null;
                    }
                    const plain = code.replace('X', '');
                    return {
                        code,
                        meaning: numbers[code] ?? null,
                        digits: [...new Set(plain.split(''))].filter((d) => digits[d]).map((d) => d + ' – ' + digits[d]),
                        water: code.startsWith('X'),
                        doubled: /(\d)\1/.test(plain),
                    };
                },
            }));
        });
    </script>
</x-layouts.app>
