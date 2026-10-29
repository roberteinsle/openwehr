@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-6">
    <div>
        <a href="{{ route('mitteilungen.manage') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Mitteilungen verwalten</a>
        <h1 class="text-xl font-bold text-gray-900 mt-1">{{ $editingId ? 'Mitteilung bearbeiten' : 'Neue Mitteilung' }}</h1>
        <p class="text-sm text-gray-500">{{ $editingId ? 'Änderungen erscheinen sofort auf der Startseite. Benachrichtigt wird nur, wenn du es unten auswählst.' : 'Erscheint auf der Startseite der Empfänger und unter „Mitteilungen“.' }}</p>
    </div>

    <form wire:submit="save" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3">
        <div>
            <input type="text" wire:model="title" aria-label="Titel" maxlength="150" placeholder="Titel" class="{{ $input }}">
            @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <div class="flex items-center gap-1 text-sm" role="tablist">
                <button type="button" role="tab" wire:click="$set('preview', false)" aria-selected="{{ $preview ? 'false' : 'true' }}" class="rounded-t-lg px-3 py-1.5 {{ $preview ? 'text-gray-500 hover:text-gray-800' : 'bg-gray-100 font-semibold text-gray-900' }}">Schreiben</button>
                <button type="button" role="tab" wire:click="$set('preview', true)" aria-selected="{{ $preview ? 'true' : 'false' }}" class="rounded-t-lg px-3 py-1.5 {{ $preview ? 'bg-gray-100 font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-800' }}">Vorschau</button>
            </div>
            @if($preview)
                {{-- As on the start page: orange card, red when important --}}
                <div class="rounded-b-lg rounded-tr-lg bg-gray-100 p-3">
                    <article class="rounded-2xl p-4 shadow-sm text-white {{ $important ? 'bg-fw-red-dark' : 'bg-fw-orange' }}">
                        <p class="text-xs font-bold uppercase tracking-wide text-white/90">{{ $important ? 'Wichtige Mitteilung' : 'Mitteilung' }}</p>
                        <p class="mt-1 text-base font-bold">{{ filled($title) ? $title : 'Titel' }}</p>
                        @if(filled($body))
                            <div class="md mt-1 text-sm font-medium">{{ \App\Support\Markdown::html($body) }}</div>
                        @else
                            <p class="mt-1 text-sm text-white/80">Noch kein Text.</p>
                        @endif
                    </article>
                    <p class="mt-2 text-xs text-gray-500">So erscheint die Mitteilung auf der Startseite. In der E-Mail kommt die Formatierung mit.</p>
                </div>
            @else
                <textarea wire:model="body" rows="10" maxlength="5000" placeholder="Text (optional)" class="{{ $input }} rounded-tl-none font-mono"></textarea>
                <details class="mt-1 text-xs text-gray-500">
                    <summary class="cursor-pointer select-none">Formatieren mit Markdown</summary>
                    <div class="mt-1 grid grid-cols-1 gap-x-6 gap-y-0.5 sm:grid-cols-2 font-mono">
                        <span>**fett** · *kursiv* · ~~durchgestrichen~~</span>
                        <span>## Zwischenüberschrift</span>
                        <span>- Punkt einer Liste</span>
                        <span>1. nummerierte Liste</span>
                        <span>[Linktext](https://…)</span>
                        <span>Leerzeile = neuer Absatz</span>
                    </div>
                </details>
            @endif
            @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            @php($picker = 'rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
            @foreach(['visibleFrom' => ['Sichtbar ab (optional)', 'Ohne Uhrzeit ab 0:00 Uhr'], 'visibleUntil' => ['Sichtbar bis einschließlich (optional)', 'Ohne Uhrzeit bis 23:59 Uhr']] as $field => [$label, $hint])
                <fieldset>
                    <legend class="text-sm text-gray-700">{{ $label }}</legend>
                    {{-- The browser's own pickers: a calendar for the date; the time is typed (e.g. 1930) or picked on the phone --}}
                    <div class="mt-1 flex gap-2">
                        <input type="date" wire:model="{{ $field }}" aria-label="{{ $label }}: Datum" class="{{ $picker }} min-w-0 flex-1">
                        <input type="time" wire:model="{{ $field }}Time" aria-label="{{ $label }}: Uhrzeit" class="{{ $picker }} w-32 shrink-0">
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $hint }}</p>
                    @error($field)<span class="text-sm text-red-600">{{ $message }}</span>@enderror
                    @error($field.'Time')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
                </fieldset>
            @endforeach
        </div>
        <div>
            <p class="text-sm text-gray-700 mb-1">Für (leer = alle Kameraden)</p>
            <div class="flex flex-wrap gap-3">
                @foreach($groups as $id => $name)
                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" value="{{ $id }}" wire:model="groupIds" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>
                @endforeach
            </div>
        </div>
        @if($functions->isNotEmpty())
            <div>
                <p class="text-sm text-gray-700 mb-1">… und/oder Funktionen (z. B. alle Maschinisten; schließt Unterfunktionen ein)</p>
                <div class="flex flex-wrap gap-3">
                    @foreach($functions as $id => $name)
                        <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" value="{{ $id }}" wire:model="functionIds" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>
                    @endforeach
                </div>
            </div>
        @endif
        <div>
            <p class="text-sm text-gray-700 mb-1">Anhänge (PDF, Office, Bilder, je bis 10 MB)</p>
            @foreach($editingFiles as $f)<p class="text-xs text-gray-600">📎 {{ $f->filename }} <button type="button" wire:click="deleteFile({{ $f->id }})" wire:confirm="Anhang löschen?" class="text-red-600 underline">entfernen</button></p>@endforeach
            <input type="file" wire:model="uploads" multiple class="text-sm">
            @error('uploads')<p class="text-sm text-red-600">{{ $message }}</p>@enderror @error('uploads.*')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="needsAck" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Lesebestätigung: jeder Empfänger bestätigt „gelesen“ – du siehst, wer noch fehlt, und kannst erinnern</label>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="important" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Wichtig (rot, oben, nicht ausblendbar)</label>
        <fieldset>
            <legend class="text-sm text-gray-700 mb-1">Benachrichtigen – auf der Startseite und unter „Mitteilungen“ steht sie immer</legend>
            <div class="space-y-1.5">
                @foreach([
                    'push' => ['Glocke und Push', 'In der Glocke und als Nachricht aufs Handy (wer Push eingeschaltet hat).'],
                    'mail' => ['Glocke, Push und E-Mail', 'Zusätzlich eine E-Mail an jeden Empfänger – für alle, die nicht regelmäßig in die App schauen.'],
                    'none' => ['Nicht benachrichtigen', $editingId ? 'Z. B. für eine kleine Korrektur, die nicht noch einmal an alle gehen soll.' : 'Nur auf der Startseite.'],
                ] as $value => [$label, $hint])
                    <label class="flex items-start gap-2 text-sm text-gray-700">
                        <input type="radio" wire:model="notify" value="{{ $value }}" class="mt-0.5 border-gray-300 text-fw-red focus:ring-fw-red">
                        <span><span class="font-medium text-gray-900">{{ $label }}</span>@if($value === 'push' && ! $editingId) <span class="text-xs text-gray-500">(Standard)</span>@endif<br><span class="text-xs text-gray-500">{{ $hint }}</span></span>
                    </label>
                @endforeach
            </div>
            @error('notify')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </fieldset>
        <div class="flex gap-3">
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">{{ $editingId ? 'Speichern' : 'Veröffentlichen' }}</button>
            <a href="{{ route('mitteilungen.manage') }}" class="inline-flex items-center px-2 text-sm text-gray-500 underline">Abbrechen</a>
        </div>
    </form>
</div>
