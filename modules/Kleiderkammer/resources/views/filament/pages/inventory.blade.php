@php($euro = fn ($cents) => \Modules\Kleiderkammer\src\Services\InventoryExport::euro($cents))
<x-filament-panels::page>
    <style>
        .inv-meta { font-size: .875rem; color: #4b5563; }
        .inv-scroll { overflow-x: auto; }
        .inv-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .inv-table th { text-align: left; padding: .5rem .75rem; font-size: .7rem; font-weight: 500; letter-spacing: .05em; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .inv-table td { padding: .55rem .75rem; border-bottom: 1px solid #f3f4f6; }
        .inv-table .num { text-align: right; white-space: nowrap; }
        .inv-table tfoot td { font-weight: 600; border-top: 2px solid #9ca3af; border-bottom: none; }
        .inv-hint { font-size: .8rem; color: #6b7280; }
        .dark .inv-meta, .dark .inv-hint { color: #9ca3af; }
        .dark .inv-table th { border-color: rgb(255 255 255 / .1); }
        .dark .inv-table td { border-color: rgb(255 255 255 / .05); }
    </style>
    <x-filament::section>
        <p class="inv-meta">Stand: {{ $printedAt->format('d.m.Y H:i') }} Uhr · Erstellt von: {{ $author }} · {{ $org }}</p>
        <div class="inv-scroll">
            <table class="inv-table">
                <thead><tr>@foreach(\Modules\Kleiderkammer\src\Services\InventoryExport::HEADINGS as $i => $heading)<th class="{{ $i === 1 ? '' : 'num' }}">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse($inventory['rows'] as $row)
                        <tr>
                            <td class="num">{{ $row['nr'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td class="num">{{ $row['quantity'] }}</td>
                            <td class="num">{{ $euro($row['net']) }}</td>
                            <td class="num">{{ $euro($row['net_sum']) }}</td>
                            <td class="num">{{ $euro($row['gross']) }}</td>
                            <td class="num">{{ $euro($row['gross_sum']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Im Lager ist nichts. Zugänge bucht der Kleiderwart unter „Lager“.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr><td></td><td>Gesamtsumme</td><td class="num">{{ $inventory['quantity_total'] }}</td><td></td><td class="num">{{ $euro($inventory['net_total']) }}</td><td></td><td class="num">{{ $euro($inventory['gross_total']) }}</td></tr>
                </tfoot>
            </table>
        </div>
        <p class="inv-hint">Gezählt wird alles, was der Kleiderkammer gehört – im Lager und an Kameraden ausgegeben, Artikel mit Code wie Artikel mit Menge; ausgemusterte Teile nicht. Preise kommen vom Artikeltyp (netto eingegeben, brutto mit {{ \Modules\Kleiderkammer\src\Models\ItemType::VAT_PERCENT }} % USt.).@if($inventory['unpriced'] > 0) {{ app(\Modules\Kleiderkammer\src\Services\InventoryExport::class)->unpricedHint($inventory['unpriced']) }}@endif</p>
    </x-filament::section>
</x-filament-panels::page>
