@php($input = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
@php($check = 'rounded border-gray-300 text-fw-red focus:ring-fw-red')
<form wire:submit="save" class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Anwesenheit</h1>
        <p class="text-sm text-gray-500">Nach einem Dienst schließt der Wehrführer (oder ein Stellvertreter) die Anwesenheit am Termin ab; die Liste geht per E-Mail mit CSV im Anhang an die Empfänger unten. Ohne Abschluss geht sie 24 Stunden nach dem Ende automatisch raus – ist dann noch keine Anwesenheit erfasst, bekommen die Abschließenden einmal eine Erinnerung statt einer leeren Liste.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    <fieldset class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <legend class="text-sm font-semibold text-gray-900">Für welche Terminarten</legend>
        <div class="flex flex-wrap gap-x-4 gap-y-1">
            @foreach($typeOptions as $key => $label)
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="types" value="{{ $key }}" class="{{ $check }}"> {{ $label }}</label>
            @endforeach
        </div>
        <p class="text-xs text-gray-500">Die Übungsdienste aus dem Übungsdienstplaner sind Termine der Art „Übung“.</p>
    </fieldset>

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach(['closerFunctionIds' => ['Wer abschließt', 'Diese Funktionen (dazu Admins) schließen ab, erfassen dafür die Anwesenheit und bekommen die Erinnerung.'], 'recipientFunctionIds' => ['Empfänger nach Funktion', 'Alle aktiven Kameraden mit dieser Funktion bekommen die Liste.']] as $field => [$title, $hint])
            <fieldset class="space-y-1 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <legend class="text-sm font-semibold text-gray-900">{{ $title }}</legend>
                <p class="text-xs text-gray-500">{{ $hint }}</p>
                @forelse($functions as $function)
                    <label class="flex items-center gap-2 text-sm text-gray-700 {{ $function->parent_id ? 'pl-5' : '' }}"><input type="checkbox" wire:model="{{ $field }}" value="{{ $function->id }}" class="{{ $check }}"> {{ $function->name }}</label>
                @empty
                    <p class="text-sm text-gray-500">Noch keine Funktionen angelegt (Admin → Kameraden → Funktionen).</p>
                @endforelse
            </fieldset>
        @endforeach
    </div>

    <fieldset class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <legend class="text-sm font-semibold text-gray-900">Weitere Empfänger</legend>
        <div class="flex flex-wrap gap-2">
            @foreach($users->whereIn('id', array_map('intval', $recipientUserIds)) as $user)
                <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-sm">{{ $user->name }}<button type="button" wire:click="removeUser({{ $user->id }})" class="text-gray-500" aria-label="{{ $user->name }} entfernen">×</button></span>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2">
            <select wire:model="addUserId" class="{{ $input }}" aria-label="Kamerad hinzufügen">
                <option value="">Kamerad wählen …</option>
                @foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach
            </select>
            <button type="button" wire:click="addUser" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">Hinzufügen</button>
        </div>
        <label class="block text-sm text-gray-700">E-Mail-Adressen außerhalb der Wehr (eine je Zeile), z. B. die Gemeindeverwaltung
            <textarea wire:model="emails" rows="3" class="{{ $input }} mt-1 w-full" placeholder="verwaltung@example.com"></textarea>
        </label>
        @error('emails')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </fieldset>

    <p class="text-sm text-gray-600">Die Liste geht zurzeit an: {{ $recipientLabel }}. Ist niemand eingestellt, geht sie an die Admins.</p>
    <div class="text-right"><button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Speichern</button></div>
</form>
