<x-filament-panels::page>
    <style>
        .st-levels { display: flex; flex-wrap: wrap; gap: .5rem; }
        .st-level { padding: .45rem .75rem; border-radius: .6rem; border: 1px solid rgb(0 0 0 / .08); background: #fff; font-size: .85rem; }
        .st-level b { font-weight: 600; }
        .st-muted { color: #6b7280; }
        .st-disposals { display: flex; flex-direction: column; gap: .35rem; font-size: .875rem; }
        .st-link { font-size: .85rem; text-decoration: underline; }
        .dark .st-level { background: #18181b; border-color: rgb(255 255 255 / .1); }
    </style>
    @if($levels->isNotEmpty())
        <div class="st-levels">
            @foreach($levels as $level)
                <span class="st-level">{{ collect([$several ? $level['store'] : null, $level['group'], $level['size']])->filter()->implode(' · ') ?: 'ohne Größe' }}: <b>{{ $level['in_store'] }}</b> im Lager<span class="st-muted">, {{ $level['issued'] }} ausgegeben</span></span>
            @endforeach
        </div>
    @endif
    {{ $this->table }}
    @if($movements->isNotEmpty())
        <x-filament::section heading="Protokoll" description="Die letzten 20 Buchungen dieses Artikels">
            <x-slot name="afterHeader"><a href="{{ \Modules\Kleiderkammer\src\Filament\Pages\MovementsPage::getUrl(['artikel' => $this->itemType->id]) }}" class="st-link">Ganzes Protokoll</a></x-slot>
            <div class="st-disposals">
                @foreach($movements as $m)
                    <p>{{ $m->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} · <b>{{ $m->kindLabel() }}</b> {{ $m->code ?? ($m->quantity > 0 && $m->kind === 'korrektur' ? '+' : '').$m->quantity.' ×' }}{{ $m->variant ? ' ('.$m->variant.')' : '' }}{{ $several && $m->store ? ' · '.$m->store->name : '' }}{{ $m->user_name ? ' · '.$m->user_name : '' }}{{ $m->reason ? ' – '.$m->reason : '' }}<span class="st-muted">{{ $m->by_name ? ' · gebucht von '.$m->by_name : '' }}</span></p>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
