@php($euro = fn ($cents) => \Modules\Kleiderkammer\src\Services\InventoryExport::euro($cents))
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Inventarliste Kleiderkammer</title>
    <style>
        @page { margin: 1.5cm 1.3cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #111827; }
        h1 { font-size: 14pt; margin: 0 0 2pt; }
        .meta { color: #374151; margin: 0 0 10pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #d1d5db; padding: 4pt 4pt; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 8pt; border-bottom: 1px solid #111827; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1.5px solid #111827; border-bottom: none; }
        .hint { color: #4b5563; font-size: 8pt; margin-top: 6pt; }
        .sign { margin-top: 36pt; width: 45%; border-top: 1px solid #111827; padding-top: 3pt; font-size: 8pt; color: #4b5563; }
    </style>
</head>
<body>
    <h1>Inventarliste Kleiderkammer</h1>
    <p class="meta">{{ $org }}<br>Stand: {{ $printedAt->format('d.m.Y H:i') }} Uhr · Erstellt von: {{ $author }}</p>
    <table>
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
                <tr><td colspan="7">Im Lager ist nichts.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="total"><td></td><td>Gesamtsumme</td><td class="num">{{ $inventory['quantity_total'] }}</td><td></td><td class="num">{{ $euro($inventory['net_total']) }}</td><td></td><td class="num">{{ $euro($inventory['gross_total']) }}</td></tr>
        </tfoot>
    </table>
    <p class="hint">Bestand im Lager: einzeln erfasste Teile, die verfügbar sind, und der Massenbestand; ausgegebene und ausgemusterte Teile zählen nicht. Brutto mit {{ \Modules\Kleiderkammer\src\Models\ItemType::VAT_PERCENT }} % USt.@if($inventory['unpriced'] > 0) {{ app(\Modules\Kleiderkammer\src\Services\InventoryExport::class)->unpricedHint($inventory['unpriced']) }}@endif</p>
    <p class="sign">Datum, Unterschrift</p>
</body>
</html>
