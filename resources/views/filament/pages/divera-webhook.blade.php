<x-filament-panels::page>
    <x-filament::section heading="Webhook-Adresse" description="DIVERA 24/7 schickt jeden Alarm an diese Adresse. Der Wachen-Monitor schaltet dann auf die Alarmansicht.">
        <div style="display:grid;gap:.75rem;font-size:.875rem">
            @if($url)
                <div>
                    <code style="display:block;word-break:break-all;padding:.25rem .5rem;border-radius:.25rem;background:rgba(127,127,127,.15);user-select:all">{{ $url }}</code>
                    <p style="margin-top:.25rem;opacity:.7">Geheim halten: Wer die Adresse kennt, kann einen Alarm auf den Monitor schicken.</p>
                </div>
                <ol style="list-style:decimal;padding-left:1.25rem;display:grid;gap:.25rem">
                    <li>In DIVERA: <strong>Verwaltung → Einstellungen → Schnittstellen → Datenübergabe</strong>, unten bei „Webhooks“ einen neuen Webhook anlegen (ab DIVERA-Version ALARM).</li>
                    <li>URL: die Adresse oben. Format: <strong>„Webhook – POST application/json“</strong>.</li>
                    <li>Inhalt: am besten <strong>„Vollständiges Objekt“</strong> – oder „Textinformationen“ bzw. „Nur Adresse und Koordinaten“. Ohne Koordinaten sucht openwehr die Adresse selbst.</li>
                </ol>
                <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                    <x-filament::button tag="a" :href="$monitorUrl" target="_blank" icon="heroicon-o-tv" color="gray">Wachen-Monitor öffnen</x-filament::button>
                    <x-filament::button wire:click="regenerateKey" wire:confirm="Neue Adresse erzeugen? Die alte funktioniert dann nicht mehr und muss in DIVERA ersetzt werden." color="gray" outlined>Neue Adresse erzeugen</x-filament::button>
                </div>
            @else
                <p style="opacity:.7">Der Webhook ist aus. Unten einschalten und speichern – dann steht hier die Adresse für DIVERA.</p>
            @endif
        </div>
    </x-filament::section>

    <form wire:submit="save" style="display:grid;gap:1rem">
        {{ $this->form }}
        <div><x-filament::button type="submit">Speichern</x-filament::button></div>
    </form>

    <x-filament::section heading="Test-Alarm" description="Simuliert einen Alarm, wie DIVERA ihn schickt. Er erscheint als TEST auf dem Wachen-Monitor; der Einsatzbericht legt dafür keinen Entwurf an.">
        <form wire:submit="sendTest" style="display:grid;gap:1rem">
            {{ $this->testForm }}
            <div><x-filament::button type="submit" color="danger" icon="heroicon-o-bell-alert" :disabled="! $url">Test-Alarm senden</x-filament::button></div>
        </form>
    </x-filament::section>

    <x-filament::section heading="Letzte Alarme" description="Die letzten zehn, 90 Tage gespeichert.">
        <div style="display:grid;gap:.5rem;font-size:.875rem">
            @forelse($alarms as $alarm)
                <div style="display:flex;gap:.75rem;align-items:flex-start;justify-content:space-between;padding:.5rem 0;border-top:1px solid rgba(127,127,127,.2)" wire:key="alarm-{{ $alarm->id }}">
                    <div style="min-width:0">
                        <p style="font-weight:600">
                            @if($alarm->isTest())<span style="color:#d97706">TEST · </span>@endif
                            {{ $alarm->keyword ?: 'Alarm ohne Stichwort' }}
                            @if(in_array($alarm->id, $running, true))<span style="margin-left:.25rem;padding:0 .375rem;border-radius:.25rem;background:#dc2626;color:#fff;font-size:.75rem">auf dem Monitor</span>@endif
                        </p>
                        <p style="opacity:.75">
                            {{ $alarm->received_at->copy()->timezone('Europe/Berlin')->format('d.m.Y H:i') }}
                            @if($alarm->address) · {{ $alarm->address }}@endif
                            · {{ match ($alarm->located_by) { 'divera' => 'Koordinaten von DIVERA', 'google' => 'Adresse über Google gefunden', 'osm' => 'Adresse über OpenStreetMap gefunden', default => 'ohne Karte' } }}
                            @if($alarm->ended_at) · beendet {{ $alarm->ended_at->copy()->timezone('Europe/Berlin')->format('H:i') }}{{ $alarm->ended_by ? ' ('.$alarm->ended_by.')' : '' }}@endif
                        </p>
                    </div>
                    @if(! $alarm->ended_at)
                        <x-filament::button size="sm" color="gray" wire:click="endAlarm({{ $alarm->id }})">Beenden</x-filament::button>
                    @endif
                </div>
            @empty
                <p style="opacity:.7">Noch kein Alarm angekommen.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
