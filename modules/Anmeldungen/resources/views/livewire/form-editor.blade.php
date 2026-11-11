@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
@php($card = 'rounded-2xl border border-gray-100 bg-white p-5 shadow-sm space-y-3')
<form wire:submit="save" class="space-y-4">
    <a href="{{ $editingId ? route('anmeldungen.show', $editingId) : route('anmeldungen.index') }}" class="inline-flex items-center gap-1 text-sm text-fw-navy">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        Zurück
    </a>
    <h1 class="text-xl font-bold text-gray-900">{{ $editingId ? 'Anmeldung bearbeiten' : 'Neue Anmeldung' }}</h1>

    <section class="{{ $card }}">
        <div>
            <label class="block text-sm font-medium text-gray-700" for="an-title">Titel</label>
            <input id="an-title" type="text" wire:model="title" maxlength="150" placeholder="z. B. Jahresabschlussfahrt der Jugendfeuerwehr" class="{{ $input }} mt-1">
            @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700" for="an-desc">Beschreibung (Markdown, z. B. Ablauf, Kosten, Packliste)</label>
            <textarea id="an-desc" wire:model="description" rows="5" class="{{ $input }} mt-1"></textarea>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div><label class="block text-sm font-medium text-gray-700">Ort</label><input type="text" wire:model="location" maxlength="200" class="{{ $input }} mt-1"></div>
            <div><label class="block text-sm font-medium text-gray-700">Anmeldeschluss</label><input type="datetime-local" wire:model="deadline" class="{{ $input }} mt-1"></div>
            <div><label class="block text-sm font-medium text-gray-700">Beginn</label><input type="date" wire:model="startsOn" class="{{ $input }} mt-1"></div>
            <div><label class="block text-sm font-medium text-gray-700">Ende</label><input type="date" wire:model="endsOn" class="{{ $input }} mt-1">@error('ends_on')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="block text-sm font-medium text-gray-700">Höchstzahl Teilnehmer (optional)</label><input type="number" min="1" wire:model="maxParticipants" class="{{ $input }} mt-1"></div>
            <div><label class="block text-sm font-medium text-gray-700">Gesundheitsangaben löschen nach (Tage nach dem Ende)</label><input type="number" min="0" max="365" wire:model="purgeDays" class="{{ $input }} mt-1"></div>
        </div>
        <fieldset>
            <legend class="text-sm font-medium text-gray-700">Für wen? (keine Auswahl = alle mit Zugang)</legend>
            <div class="mt-1 flex flex-wrap gap-4 text-sm text-gray-700">
                @foreach($groups as $id => $name)
                    <label class="flex items-center gap-2"><input type="checkbox" value="{{ $id }}" wire:model="groupIds" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>
                @endforeach
            </div>
        </fieldset>
    </section>

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">Felder</h2>
            <button type="button" wire:click="useTemplate" wire:confirm="Felder und Erklärungen durch die Vorlage für Fahrt/Zeltlager ersetzen?" class="text-sm text-fw-navy underline">Vorlage Fahrt/Zeltlager</button>
        </div>
        <p class="text-xs text-gray-500">„Gesundheit“ kennzeichnet Gesundheitsdaten (Allergien, Medikamente …): verschlüsselt und nach der Veranstaltung automatisch gelöscht.</p>
        @foreach($fields as $i => $field)
            <div class="space-y-2 rounded-xl border border-gray-100 p-3" wire:key="fe-{{ $i }}">
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_12rem]">
                    <input type="text" wire:model="fields.{{ $i }}.label" maxlength="200" placeholder="Frage, z. B. Schwimmer" class="{{ $input }}">
                    <select wire:model.live="fields.{{ $i }}.type" class="{{ $input }}">
                        @foreach(\Modules\Anmeldungen\src\Models\Form::TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                @if(($field['type'] ?? '') === 'choice')
                    <textarea wire:model="fields.{{ $i }}.options" rows="3" placeholder="Antworten, eine pro Zeile" class="{{ $input }}"></textarea>
                @endif
                <input type="text" wire:model="fields.{{ $i }}.help" maxlength="300" placeholder="Hinweis (optional)" class="{{ $input }}">
                <div class="flex flex-wrap items-center gap-4 text-sm text-gray-700">
                    <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="fields.{{ $i }}.required" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Pflicht</label>
                    <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="fields.{{ $i }}.health" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Gesundheit</label>
                    <span class="ml-auto flex gap-3 text-xs">
                        <button type="button" wire:click="moveField({{ $i }}, -1)" class="underline" aria-label="Nach oben">hoch</button>
                        <button type="button" wire:click="moveField({{ $i }}, 1)" class="underline" aria-label="Nach unten">runter</button>
                        <button type="button" wire:click="removeField({{ $i }})" class="text-red-700 underline">entfernen</button>
                    </span>
                </div>
                @error('fields.'.$i)<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <button type="button" wire:click="addField" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Feld hinzufügen</button>
    </section>

    <section class="{{ $card }}">
        <h2 class="text-base font-semibold text-gray-900">Einverständniserklärung</h2>
        <p class="text-xs text-gray-500">Jede Erklärung wird einzeln bestätigt, z. B. Aufsichtspflicht, Verhalten, Gesundheit, Notfall.</p>
        @foreach($consents as $i => $consent)
            <div class="flex items-start gap-2" wire:key="ce-{{ $i }}">
                <textarea wire:model="consents.{{ $i }}.text" rows="2" maxlength="2000" class="{{ $input }}"></textarea>
                <button type="button" wire:click="removeConsent({{ $i }})" class="mt-2 text-xs text-red-700 underline">entfernen</button>
            </div>
        @endforeach
        <button type="button" wire:click="addConsent" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Erklärung hinzufügen</button>
    </section>

    <section class="{{ $card }}">
        <h2 class="text-base font-semibold text-gray-900">Unterschrift</h2>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="requireSignature" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Am Bildschirm unterschreiben</label>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="guardianRequired" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Kameraden mit Erziehungsberechtigten werden von diesen angemeldet (Eltern-Bereich)</label>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="memberSignature" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Der Jugendliche unterschreibt zusätzlich</label>
    </section>

    <div class="flex items-center gap-3">
        <button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Speichern</button>
        @unless($editingId)<span class="text-sm text-gray-500">Gespeichert wird als Entwurf; geöffnet wird auf der nächsten Seite.</span>@endunless
    </div>
</form>
