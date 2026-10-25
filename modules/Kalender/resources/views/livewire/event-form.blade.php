@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="max-w-2xl mx-auto">
    <a href="{{ $event ? route('kalender.show', $event) : route('kalender.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← {{ $event ? 'Termin' : 'Kalender' }}</a>
    <h1 class="text-xl font-bold text-gray-900 mt-1 mb-4">{{ $event ? 'Termin bearbeiten' : 'Neuer Termin' }}</h1>

    @if($flash)<p class="mb-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @if($templates->isNotEmpty())
        <div class="mb-4 flex flex-wrap items-end gap-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <label class="block flex-1 text-sm font-medium text-gray-700">Aus Vorlage
                <select wire:model="templateId" class="mt-1 {{ $input }}"><option value="">– Vorlage wählen –</option>@foreach($templates as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </label>
            <button type="button" wire:click="applyTemplate" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">Übernehmen</button>
            @if($templateId)<button type="button" wire:click="deleteTemplate" wire:confirm="Vorlage löschen?" class="text-sm text-red-600 underline">löschen</button>@endif
        </div>
    @endif

    <form wire:submit="save" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="block text-sm font-medium text-gray-700 sm:col-span-2">Titel <span class="text-red-600">*</span>
                <input type="text" wire:model="title" maxlength="150" placeholder="z. B. Übungsdienst Technische Hilfe" class="mt-1 {{ $input }}">
                @error('title')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="block text-sm font-medium text-gray-700">Art
                <select wire:model="type" class="mt-1 {{ $input }}">@foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="allDay" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Ganztägig</label>

        @if($allDay)
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block text-sm font-medium text-gray-700">Von Tag
                    <input type="date" wire:model.live="startDate" class="mt-1 {{ $input }}">
                </label>
                <label class="block text-sm font-medium text-gray-700">Bis Tag (einschließlich)
                    <input type="date" wire:model="endDate" min="{{ $startDate }}" class="mt-1 {{ $input }}">
                </label>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block text-sm font-medium text-gray-700">Beginn
                    <input type="datetime-local" wire:model.live.debounce.500ms="startsAt" class="mt-1 {{ $input }}">
                </label>
                <label class="block text-sm font-medium text-gray-700">Ende
                    <input type="datetime-local" wire:model="endsAt" min="{{ $startsAt }}" class="mt-1 {{ $input }}">
                </label>
            </div>
            <p class="text-xs text-gray-500">Ein neuer Termin dauert 60 Minuten; wird der Beginn verschoben, wandert das Ende mit.</p>
        @endif
        @foreach(['startsAt', 'endsAt', 'startDate', 'endDate'] as $field)@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach

        @include('partials.place-dress-fields', ['input' => $input, 'places' => $places, 'dressCodes' => $dressCodes, 'placeId' => $placeId, 'manageUrl' => route('kalender.places')])
        <label class="block text-sm font-medium text-gray-700">Gemeinsam mit (optional)
            <input type="text" wire:model="partners" maxlength="300" placeholder="z. B. FF Nachbarstadt, THW Ortsverband – für gemeinsame Übungen" class="mt-1 {{ $input }}">
        </label>
        <label class="block text-sm font-medium text-gray-700">Ausführend (optional)
            <input type="text" wire:model="organizer" maxlength="100" placeholder="z. B. Vorstand, Wehrführung, Festausschuss – steht im Dienstplan" class="mt-1 {{ $input }}">
        </label>
        <label class="block text-sm font-medium text-gray-700">Beschreibung
            <textarea wire:model="description" rows="3" maxlength="5000" class="mt-1 {{ $input }}"></textarea>
        </label>

        <div>
            <p class="text-sm font-medium text-gray-700 mb-1">Für (leer = alle Kameraden mit Kalender)</p>
            <div class="flex flex-wrap gap-3">
                @foreach($groups as $id => $name)
                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" value="{{ $id }}" wire:model="groupIds" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>
                @endforeach
            </div>
        </div>

        @if($functions->isNotEmpty())
            <div>
                <p class="text-sm font-medium text-gray-700 mb-1">Für Funktionen (zusätzlich; eine Funktion umfasst ihre Unterfunktionen)</p>
                <div class="flex flex-wrap gap-3">
                    @foreach($functions as $id => $name)
                        <label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" value="{{ $id }}" wire:model="functionIds" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>
                    @endforeach
                </div>
            </div>
        @endif

        <div>
            <label for="kal-invitees" class="block text-sm font-medium text-gray-700 mb-1">Einzelne Kameraden einladen (zusätzlich)</label>
            <select id="kal-invitees" multiple size="5" wire:model="inviteeIds" class="{{ $input }}">
                @foreach($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">Mehrere mit Strg/Cmd. Ohne Abteilungen, Funktionen und Einladungen gilt der Termin für alle.</p>
        </div>

        <div class="rounded-xl bg-gray-50 p-3 space-y-2">
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="rsvp" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Rückmeldung erwünscht (zusagen, absagen, vielleicht)</label>
            @if($rsvp)
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="block text-sm text-gray-700">Rückmeldung bis (optional)
                        <div class="mt-1 flex gap-2">
                            <input type="date" wire:model="rsvpDeadline" class="{{ $input }} min-w-0 flex-1" aria-label="Rückmeldung bis – Datum">
                            <input type="time" wire:model="rsvpDeadlineTime" class="{{ $input }} w-32" aria-label="Rückmeldung bis – Uhrzeit" title="Leer = bis Ende des Tages">
                        </div>
                        <span class="text-xs text-gray-500">Ohne Uhrzeit bis 23:59 Uhr.</span>
                        @error('rsvpDeadlineTime')<span class="block text-sm text-red-600">Bitte die Uhrzeit als HH:MM angeben.</span>@enderror
                    </div>
                    <label class="block text-sm text-gray-700">Mindestens Kameraden benötigt (optional)
                        <input type="number" min="1" max="999" wire:model="minParticipants" placeholder="z. B. 9" class="mt-1 {{ $input }}">
                        @error('minParticipants')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
                    </label>
                </div>
                <p class="text-xs text-gray-500">Wer nicht geantwortet hat, bekommt einmal eine Erinnerung: zwei Tage vor der Frist, ohne Frist drei Tage vor dem Termin.</p>
            @endif
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="isPublic" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Öffentlich – erscheint auch auf der Website der Wehr (nur Titel, Zeit, Ort und Beschreibung)</label>

        @if(! $event)
            <div class="rounded-xl bg-gray-50 p-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block text-sm text-gray-700">Wiederholen
                    <select wire:model.live="repeat" class="mt-1 {{ $input }}"><option value="">Nicht wiederholen</option>@foreach($repeats as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                </label>
                @if($repeat)
                    <label class="block text-sm text-gray-700">bis einschließlich
                        <input type="date" wire:model="repeatUntil" class="mt-1 {{ $input }}">
                        @error('repeatUntil')<span class="text-sm text-red-600">{{ $message }}</span>@enderror
                    </label>
                @endif
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="notify" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Kameraden per E-Mail informieren</label>
        @else
            @if($inSeries)
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="applyToFollowing" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Titel, Art, Ort, Beschreibung, Rückmeldung und Zielgruppe auch für die folgenden Termine dieser Reihe übernehmen (Zeiten bleiben)</label>
            @endif
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="notify" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Änderung an alle der Zielgruppe per E-Mail schicken</label>
            <p class="text-xs text-gray-500">Neue Zeit oder neuer Ort gehen auch ohne Häkchen an alle, die zu- oder vielleicht zugesagt haben.</p>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">{{ $event ? 'Speichern' : 'Termin anlegen' }}</button>
            <span class="ml-auto flex items-center gap-2">
                <input type="text" wire:model="templateName" maxlength="100" placeholder="Name der Vorlage" class="w-44 rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                <button type="button" wire:click="saveTemplate" class="text-sm text-fw-navy underline">Als Vorlage speichern</button>
            </span>
        </div>
        @error('templateName')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </form>
</div>
