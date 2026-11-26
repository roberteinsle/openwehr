<x-filament-widgets::widget>
    <x-filament::section heading="Fahrzeug-Monitor" description="Nur-Lese-Ansicht für den Bildschirm in der Wache, aktualisiert sich jede Minute.">
        <div style="display:grid;gap:.75rem;font-size:.875rem">
            <x-filament::button tag="a" :href="$adminUrl" target="_blank" icon="heroicon-o-tv">
                Monitor öffnen
            </x-filament::button>

            @if($stationUrl)
            <div>
                <p style="font-weight:500">Link für den Wachen-PC (ohne Login):</p>
                <code style="display:block;margin-top:.25rem;word-break:break-all;padding:.25rem .5rem;border-radius:.25rem;background:rgba(127,127,127,.15);user-select:all">{{ $stationUrl }}</code>
                <p style="margin-top:.25rem;opacity:.7">Nur an den Wachen-PC weitergeben. Wer den Link kennt, sieht den Monitor. Neuer Schlüssel in Coolify (<code>DISPLAY_TOKEN</code>) macht den alten Link ungültig.</p>
            </div>
            @else
            <p style="opacity:.7">Für den Wachen-PC in Coolify <code>DISPLAY_TOKEN</code> setzen (z. B. 48 zufällige Zeichen). Bis dahin können nur Admins den Monitor öffnen.</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
