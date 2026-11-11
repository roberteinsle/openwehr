<x-filament-panels::page>
    <style>
        .at-list { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .at-list td { padding: .5rem .25rem; border-top: 1px solid rgb(229 231 235 / .6); vertical-align: middle; }
        .at-list td.at-actions { text-align: right; white-space: nowrap; }
        .at-muted { color: rgb(107 114 128); }
        .at-name { font-weight: 600; }
        .at-name a:hover { text-decoration: underline; }
    </style>

    <x-filament::section icon="heroicon-o-user-plus" heading="Bei den Übungsdiensten mitmachen" description="Alt genug, um bei den Übungsdiensten der anderen Abteilung mitzumachen. Als Gast bekommen sie deren Übungen in den Kalender und sehen sie im Übungsdienstplan; alles andere bleibt wie in der Jugendabteilung.">
        @if($guestCandidates->isEmpty())
            <p class="at-muted">Gerade niemand.</p>
        @else
            <table class="at-list">
                @foreach($guestCandidates as $c)
                    <tr wire:key="g-{{ $c['user']->id }}-{{ $c['to']->id }}">
                        <td><span class="at-name"><a href="{{ $editUrl($c['user']) }}">{{ $c['user']->name }}</a></span> <span class="at-muted">· {{ $c['age'] }} Jahre · {{ $c['from']->name }}</span></td>
                        <td class="at-actions"><x-filament::button size="sm" wire:click="addGuest({{ $c['user']->id }}, {{ $c['to']->id }})">Bei der {{ $c['to']->name }} mitüben lassen</x-filament::button></td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>

    <x-filament::section icon="heroicon-o-arrow-right-circle" heading="Übertritt" description="Das Übertrittsalter ist erreicht: Mit dem Übertritt verlässt der Kamerad die Jugendabteilung und wird Mitglied der anderen Abteilung – mit deren Modulen.">
        @if($transferCandidates->isEmpty())
            <p class="at-muted">Gerade niemand.</p>
        @else
            <table class="at-list">
                @foreach($transferCandidates as $c)
                    <tr wire:key="t-{{ $c['user']->id }}">
                        <td><span class="at-name"><a href="{{ $editUrl($c['user']) }}">{{ $c['user']->name }}</a></span> <span class="at-muted">· {{ $c['age'] }} Jahre · {{ $c['from']->name }}</span></td>
                        <td class="at-actions"><x-filament::button size="sm" color="warning" wire:click="transfer({{ $c['user']->id }}, {{ $c['from']->id }}, {{ $c['to']->id }})" wire:confirm="{{ $c['user']->name }} von der {{ $c['from']->name }} in die {{ $c['to']->name }} übernehmen?">In die {{ $c['to']->name }} übernehmen</x-filament::button></td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>

    <x-filament::section icon="heroicon-o-users" heading="Üben mit (Gäste)" collapsible>
        @if($guests->isEmpty())
            <p class="at-muted">Niemand übt als Gast bei einer anderen Abteilung mit.</p>
        @else
            <table class="at-list">
                @foreach($guests as $g)
                    <tr wire:key="x-{{ $g['user']->id }}-{{ $g['group']->id }}">
                        <td><span class="at-name"><a href="{{ $editUrl($g['user']) }}">{{ $g['user']->name }}</a></span> <span class="at-muted">· übt mit bei der {{ $g['group']->name }}{{ $g['user']->age() !== null ? ' · '.$g['user']->age().' Jahre' : '' }}</span></td>
                        <td class="at-actions"><x-filament::button size="sm" color="gray" wire:click="removeGuest({{ $g['user']->id }}, {{ $g['group']->id }})" wire:confirm="Beenden?">Beenden</x-filament::button></td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>

    @if($missing->isNotEmpty())
        <x-filament::section icon="heroicon-o-exclamation-triangle" heading="Geburtsdatum fehlt" description="Ohne Geburtsdatum kennt openwehr das Alter nicht. Eintragen unter Kamerad bearbeiten (optional, nur für Admins sichtbar)." collapsible>
            <p>
                @foreach($missing as $m)<a href="{{ $editUrl($m) }}" style="text-decoration: underline;">{{ $m->name }}</a>@if(! $loop->last), @endif @endforeach
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
