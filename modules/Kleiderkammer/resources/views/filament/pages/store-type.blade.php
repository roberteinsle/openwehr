<x-filament-panels::page>
    <style>
        .st-levels { display: flex; flex-wrap: wrap; gap: .5rem; }
        .st-level { padding: .45rem .75rem; border-radius: .6rem; border: 1px solid rgb(0 0 0 / .08); background: #fff; font-size: .85rem; }
        .st-level b { font-weight: 600; }
        .st-muted { color: #6b7280; }
        .st-disposals { display: flex; flex-direction: column; gap: .35rem; font-size: .875rem; }
        .dark .st-level { background: #18181b; border-color: rgb(255 255 255 / .1); }
    </style>
    @if($levels->isNotEmpty())
        <div class="st-levels">
            @foreach($levels as $level)
                <span class="st-level">{{ collect([$level['group'], $level['size']])->filter()->implode(' · ') ?: 'ohne Größe' }}: <b>{{ $level['in_store'] }}</b> im Lager<span class="st-muted">, {{ $level['issued'] }} ausgegeben</span></span>
            @endforeach
        </div>
    @endif
    {{ $this->table }}
    @if($disposals->isNotEmpty())
        <x-filament::section heading="Ausgemustert" description="Die letzten 20 Ausgänge dieses Artikels">
            <div class="st-disposals">
                @foreach($disposals as $d)
                    <p>{{ $d->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} · <b>{{ $d->item?->code ?? $d->quantity.' ×' }}</b>{{ collect([$d->group?->name, $d->size?->name])->filter()->isNotEmpty() ? ' ('.collect([$d->group?->name, $d->size?->name])->filter()->implode(', ').')' : '' }} – {{ $d->reason }}<span class="st-muted">{{ $d->fromUser ? ' · von '.$d->fromUser->name : ' · aus dem Lager' }}{{ $d->user ? ' · gebucht von '.$d->user->name : '' }}</span></p>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
