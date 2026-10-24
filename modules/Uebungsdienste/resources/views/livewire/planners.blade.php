<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Planer & Abteilungen</h1>
        <p class="text-sm text-gray-500">Für welche Abteilungen ein Übungsdienstplaner Dienste anlegt, ändert und löscht – z. B. die Jugendwarte nur für die Jugendfeuerwehr. Ohne Haken plant er für alle und die ganze Wehr. Wer mehrere Abteilungen hat, wählt beim Anlegen, für welche er gerade plant. Die Rolle „Übungsdienstplaner“ vergibst du unter Admin → Module. Admins dürfen immer alles.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('scope')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @forelse($planners as $planner)
        <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" wire:key="p-{{ $planner->id }}">
            <p class="font-semibold text-gray-900">{{ $planner->name }}</p>
            <div class="flex flex-wrap gap-4 text-sm text-gray-700">
                @foreach($groups as $id => $name)
                    <label class="flex items-center gap-2"><input type="checkbox" value="{{ $id }}" wire:model="scopes.{{ $planner->id }}" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>
                @endforeach
            </div>
            <button type="button" wire:click="save({{ $planner->id }})" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Speichern</button>
        </section>
    @empty
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Noch niemand hat die Rolle „Übungsdienstplaner“.</p>
    @endforelse
</div>
