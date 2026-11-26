<x-filament-panels::page>
    <x-filament::section heading="Link" description="Nur-Lese-Ansicht für einen Bildschirm in der Wache, aktualisiert sich jede Minute.">
        <div style="display:grid;gap:.75rem;font-size:.875rem">
            <div>
                <x-filament::button tag="a" :href="$adminUrl" target="_blank" icon="heroicon-o-tv">
                    Monitor öffnen
                </x-filament::button>
            </div>

            @if($stationUrl)
                <div>
                    <p style="font-weight:500">Link für den Wachen-PC (ohne Login):</p>
                    <code style="display:block;margin-top:.25rem;word-break:break-all;padding:.25rem .5rem;border-radius:.25rem;background:rgba(127,127,127,.15);user-select:all">{{ $stationUrl }}</code>
                    <p style="margin-top:.25rem;opacity:.7">Nur an den Wachen-PC weitergeben. Wer den Link kennt, sieht den Monitor. Ein neuer Schlüssel (<code>DISPLAY_TOKEN</code>) macht den alten Link ungültig – auch den der Fahrzeugbelegung und des Termin-Monitors.</p>
                </div>
            @else
                <p style="opacity:.7">Für den Wachen-PC in der Umgebung (z. B. Coolify) <code>DISPLAY_TOKEN</code> setzen, z. B. mit <code>openssl rand -hex 24</code>. Bis dahin können nur Admins den Monitor öffnen.</p>
            @endif
        </div>
    </x-filament::section>

    <form wire:submit="save" style="display:grid;gap:1.5rem">
        {{ $this->form }}

        <div style="display:flex;gap:.75rem;flex-wrap:wrap">
            <x-filament::button type="submit">Speichern</x-filament::button>
            <x-filament::button wire:click="resetToDefaults" color="gray" outlined>Standard wiederherstellen</x-filament::button>
        </div>
    </form>

    <x-filament::section heading="Mögliche Inhalte" description="Nur von eingeschalteten Modulen. Namen erscheinen auf dem Monitor höchstens gekürzt („Hanna H.“).">
        <dl style="display:grid;gap:.5rem;font-size:.875rem">
            @forelse($contents as $content)
                <div>
                    <dt style="font-weight:600">{{ $content['title'] }}</dt>
                    <dd style="opacity:.75">{{ $content['description'] }}</dd>
                </div>
            @empty
                <p style="opacity:.7">Kein Modul mit Inhalten für den Monitor ist eingeschaltet.</p>
            @endforelse
        </dl>
    </x-filament::section>
</x-filament-panels::page>
