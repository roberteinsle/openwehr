@php($input = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Terminarten</h1>
        <p class="text-sm text-gray-500">Die Arten, aus denen beim Anlegen eines Termins gewählt wird – mit Namen, Farbe (Kalender, Startseite, Dienstplan) und Reihenfolge. Ausgeschaltete Arten werden nicht mehr angeboten; Termine, die sie schon haben, behalten sie. Löschen geht nur bei Arten, die nirgends verwendet werden. „Übung“, „Veranstaltung“, „Wartung“ und „Sonstiges“ verwenden auch andere Module (Übungsdienstplaner, Schichtplan, Fahrzeuge, Führerscheine) – sie lassen sich umbenennen, aber nicht löschen.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('delete')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <form wire:submit="save" class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @foreach($types as $key => $type)
            <div class="flex flex-wrap items-center gap-3 p-3" wire:key="t-{{ $type->id }}">
                <span class="flex flex-col">
                    <button type="button" wire:click="move({{ $type->id }}, -1)" @disabled($loop->first) class="text-xs text-gray-500 disabled:opacity-30" aria-label="nach oben">▲</button>
                    <button type="button" wire:click="move({{ $type->id }}, 1)" @disabled($loop->last) class="text-xs text-gray-500 disabled:opacity-30" aria-label="nach unten">▼</button>
                </span>
                <input type="color" wire:model.live="rows.{{ $type->id }}.color" class="h-9 w-12 cursor-pointer rounded border border-gray-300" aria-label="Farbe">
                <input type="text" wire:model="rows.{{ $type->id }}.label" maxlength="60" class="{{ $input }} min-w-0 flex-1" aria-label="Name">
                <span class="rounded border px-1.5 py-0.5 text-xs" style="background-color: {{ $rows[$type->id]['color'] ?? $type->color }}1A; color: {{ $rows[$type->id]['color'] ?? $type->color }}; border-color: {{ $rows[$type->id]['color'] ?? $type->color }}55">{{ $rows[$type->id]['label'] ?? $type->label }}</span>
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="rows.{{ $type->id }}.is_active" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> aktiv</label>
                @unless(in_array($key, $locked, true))
                    <button type="button" wire:click="delete({{ $type->id }})" wire:confirm="„{{ $type->label }}“ löschen?" class="text-sm text-red-600 underline">Löschen</button>
                @endunless
                @error('rows.'.$type->id.'.label')<p class="w-full text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <div class="p-3 text-right"><button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Speichern</button></div>
    </form>

    <form wire:submit="add" class="flex flex-wrap items-center gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="w-full text-sm font-semibold text-gray-900">Neue Terminart</p>
        <input type="color" wire:model="newColor" class="h-9 w-12 cursor-pointer rounded border border-gray-300" aria-label="Farbe">
        <input type="text" wire:model="newLabel" maxlength="60" placeholder="z. B. Jugenddienst, Sitzung, Lehrgang" class="{{ $input }} min-w-0 flex-1">
        <button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Anlegen</button>
        @error('newLabel')<p class="w-full text-sm text-red-600">{{ $message }}</p>@enderror
    </form>
</div>
