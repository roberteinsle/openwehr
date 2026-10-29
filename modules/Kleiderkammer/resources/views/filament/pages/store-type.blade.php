<x-filament-panels::page>
    <style>
        .st-levels { display: flex; flex-wrap: wrap; gap: .5rem; }
        .st-level { padding: .45rem .75rem; border-radius: .6rem; border: 1px solid rgb(0 0 0 / .08); background: #fff; font-size: .85rem; }
        .st-level b { font-weight: 600; }
        .st-muted { color: #6b7280; }
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
</x-filament-panels::page>
