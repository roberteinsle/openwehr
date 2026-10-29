<x-filament-widgets::widget>
    <style>
        .ir { display: flex; flex-direction: column; gap: 1.5rem; }
        .ir-success { background: #dcfce7; color: #15803d; }
        .ir-warning { background: #fef3c7; color: #b45309; }
        .ir-danger { background: #fee2e2; color: #b91c1c; }
        .ir-info { background: #dbeafe; color: #1d4ed8; }
        .ir-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 28rem), 1fr)); gap: 1.5rem; align-items: start; }
        .ir-list { display: flex; flex-direction: column; gap: 1.1rem; }
        .ir-row-head { display: flex; justify-content: space-between; gap: .5rem; font-size: .9rem; margin-bottom: .35rem; }
        .ir-row-head strong { font-weight: 600; }
        .ir-muted { color: #6b7280; }
        .ir-bar { display: flex; height: .5rem; border-radius: 9999px; overflow: hidden; background: #f3f4f6; }
        .ir-bar > i { display: block; height: 100%; }
        .ir-legend { display: flex; flex-wrap: wrap; gap: .25rem 1rem; margin-top: .35rem; font-size: .75rem; color: #6b7280; }
        .ir-dot { display: inline-block; width: .5rem; height: .5rem; border-radius: 9999px; margin-right: .3rem; }
        .ir-scroll { overflow-x: auto; }
        .ir-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .ir-table th { text-align: left; padding: 0 .75rem .6rem 0; font-size: .7rem; font-weight: 500; letter-spacing: .05em; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .ir-table td { padding: .6rem .75rem .6rem 0; border-bottom: 1px solid #f3f4f6; }
        .ir-table .num { text-align: right; padding-right: 0; }
        .ir-mono { font-family: ui-monospace, monospace; font-size: .75rem; }
        .ir-zero td { color: #dc2626; font-weight: 600; }
        .ir-link { color: rgb(var(--primary-600, 37 99 235)); text-decoration: underline; }
        .ir-badge { display: inline-block; min-width: 1.75rem; padding: .1rem .5rem; border-radius: .4rem; font-size: .8rem; font-weight: 600; text-align: center; }
        .dark .ir-bar { background: rgb(255 255 255 / .1); }
        .dark .ir-table th { border-color: rgb(255 255 255 / .1); }
        .dark .ir-table td { border-color: rgb(255 255 255 / .05); }
        .dark .ir-success { background: rgb(34 197 94 / .15); color: #4ade80; }
        .dark .ir-warning { background: rgb(245 158 11 / .15); color: #fbbf24; }
        .dark .ir-danger { background: rgb(239 68 68 / .15); color: #f87171; }
        .dark .ir-info { background: rgb(59 130 246 / .15); color: #60a5fa; }
    </style>
    <div class="ir">
        <div class="ir-cols">
            {{-- Einzelteile --}}
            <x-filament::section icon="heroicon-o-qr-code" heading="Artikel mit Code" description="Die fünf mit den meisten Teilen">
                @if($trackedByType->isEmpty())
                    <p class="ir-muted">Noch keine Artikel mit Code.</p>
                @else
                    <div class="ir-list">
                        @foreach($trackedByType as $row)
                            @php($pct = fn ($n) => $row->total > 0 ? round($n / $row->total * 100, 1) : 0)
                            <div>
                                <div class="ir-row-head">
                                    <strong>{{ $row->name }}</strong>
                                    <span class="ir-muted">{{ $row->total }} gesamt</span>
                                </div>
                                <div class="ir-bar">
                                    <i style="background:#22c55e;width: {{ $pct($row->available) }}%"></i>
                                    <i style="background:#f59e0b;width: {{ $pct($row->issued) }}%"></i>
                                    <i style="background:#ef4444;width: {{ $pct($row->retired) }}%"></i>
                                </div>
                                <div class="ir-legend">
                                    <span><span class="ir-dot" style="background:#22c55e"></span>{{ $row->available }} verfügbar</span>
                                    <span><span class="ir-dot" style="background:#f59e0b"></span>{{ $row->issued }} ausgegeben</span>
                                    <span><span class="ir-dot" style="background:#ef4444"></span>{{ $row->retired }} ausgemustert</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>

            {{-- Massenbestand --}}
            <x-filament::section icon="heroicon-o-cube" heading="Artikel mit Menge" description="Die fünf mit dem kleinsten Bestand">
                @if($stockByType->isEmpty())
                    <p class="ir-muted">Noch keine Artikel mit Menge.</p>
                @else
                    <div class="ir-scroll">
                        <table class="ir-table">
                            <thead>
                                <tr>
                                    <th>Artikel</th>
                                    <th>Gruppe / Größe</th>
                                    <th class="num">Bestand</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($stockByType as $row)
                                    <tr @class(['ir-zero' => $row->quantity <= 0])>
                                        <td>{{ $row->name }}</td>
                                        <td class="ir-muted">{{ $row->size ?? '–' }}</td>
                                        <td class="num">
                                            <span class="ir-badge ir-{{ $row->quantity <= 0 ? 'danger' : ($row->quantity <= 2 ? 'warning' : 'success') }}">{{ $row->quantity }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>

        {{-- Offene Leihen --}}
        <x-filament::section icon="heroicon-o-clock" heading="Letzte Ausleihe" description="Die fünf neuesten Ausgaben, die noch bei Kameraden sind">
            @if($openLoans->isEmpty())
                <p class="ir-muted">Es ist nichts ausgeliehen.</p>
            @else
                <div class="ir-scroll">
                    <table class="ir-table">
                        <thead>
                            <tr>
                                <th>Kamerad</th>
                                <th>Artikel</th>
                                <th>Code / Größe</th>
                                <th>Ausgegeben am</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($openLoans as $loan)
                                <tr>
                                    <td>@if($loan->user)<a href="{{ \Modules\Kleiderkammer\src\Filament\Resources\MemberResource::getUrl('edit', ['record' => $loan->user]) }}" class="ir-link">{{ $loan->user->name }}</a>@else – @endif</td>
                                    <td>{{ $loan->item?->itemType?->name ?? $loan->itemType?->name ?? '–' }}</td>
                                    <td class="ir-mono">{{ $loan->item?->code ?? ($loan->size?->name ?? '–') }}</td>
                                    <td class="ir-muted">{{ $loan->issued_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
