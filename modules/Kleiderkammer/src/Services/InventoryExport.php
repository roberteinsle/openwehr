<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use App\Services\OrganizationSettings;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Modules\Kleiderkammer\src\Models\ItemType;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageMargin;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The Inventar of the Kleiderkammer as PDF and as Excel file, both laid out for DIN A4 portrait,
 * with the date and the Kamerad who printed it above the table.
 */
class InventoryExport
{
    public const HEADINGS = ['Lfd. Nr.', 'Artikel', 'Anzahl', 'Einzelpreis netto', 'Summe netto', 'Einzelpreis brutto', 'Summe brutto'];

    public function __construct(private readonly StoreService $store, private readonly OrganizationSettings $org) {}

    /** @return array{inventory: array, org: string, author: string, printedAt: Carbon} */
    public function data(User $author): array
    {
        return ['inventory' => $this->store->inventory(), 'org' => $this->org->name(), 'author' => $author->name.($author->hasModuleRole('kleiderkammer', 'kleiderwart') ? ' (Kleiderwart)' : ''), 'printedAt' => now('Europe/Berlin')];
    }

    public function filename(string $extension): string
    {
        return 'inventar-kleiderkammer-'.now('Europe/Berlin')->format('Y-m-d').'.'.$extension;
    }

    public function pdf(User $author): string
    {
        return Pdf::loadView('kleiderkammer::pdf.inventory', $this->data($author))->setPaper('a4')->output();
    }

    /** Numbers stay numbers (euro with two decimals), so Excel can keep calculating; text is never a formula */
    public function xlsx(User $author): string
    {
        $data = $this->data($author);
        $options = new Options;
        $options->setPageSetup(new PageSetup(PageOrientation::PORTRAIT, PaperSize::A4, fitToHeight: 0, fitToWidth: 1));
        $options->setPageMargin(new PageMargin(top: 0.6, right: 0.5, bottom: 0.6, left: 0.5));
        $options->setColumnWidth(8, 1);
        $options->setColumnWidth(34, 2);
        $options->setColumnWidth(9, 3);
        $options->setColumnWidthForRange(15, 4, 7);

        $path = tempnam(sys_get_temp_dir(), 'kk-inventar');
        $writer = new Writer($options);
        $writer->openToFile($path);

        $bold = (new Style)->setFontBold();
        $line = new Border(new BorderPart(Border::BOTTOM, '000000', Border::WIDTH_THIN));
        $head = (new Style)->setFontBold()->setBackgroundColor('F3F4F6')->setBorder($line)->setShouldWrapText();
        $money = (new Style)->setFormat('#,##0.00 "€"');
        $count = (new Style)->setCellAlignment(CellAlignment::RIGHT);
        $totalMoney = (new Style)->setFontBold()->setFormat('#,##0.00 "€"');

        $writer->addRow(new Row([new StringCell('Inventarliste Kleiderkammer – '.$data['org'], (new Style)->setFontBold()->setFontSize(14))]));
        $writer->addRow(new Row([new StringCell('Stand: '.$data['printedAt']->format('d.m.Y H:i').' Uhr', null)]));
        $writer->addRow(new Row([new StringCell('Erstellt von: '.$data['author'], null)]));
        $writer->addRow(new Row([new EmptyCell(null, null)]));
        $writer->addRow(new Row(array_map(fn (string $h) => new StringCell($h, $head), self::HEADINGS)));

        $euro = fn (?int $cents, ?Style $style) => $cents === null ? new StringCell('–', $count) : new NumericCell($cents / 100, $style);
        foreach ($data['inventory']['rows'] as $row) {
            $writer->addRow(new Row([
                new NumericCell($row['nr'], null), new StringCell($row['name'], null), new NumericCell($row['quantity'], null),
                $euro($row['net'], $money), $euro($row['net_sum'], $money), $euro($row['gross'], $money), $euro($row['gross_sum'], $money),
            ]));
        }
        $writer->addRow(new Row([
            new EmptyCell(null, $bold), new StringCell('Gesamtsumme', $bold), new NumericCell($data['inventory']['quantity_total'], $bold),
            new EmptyCell(null, null), new NumericCell($data['inventory']['net_total'] / 100, $totalMoney),
            new EmptyCell(null, null), new NumericCell($data['inventory']['gross_total'] / 100, $totalMoney),
        ], (new Style)->setBorder(new Border(new BorderPart(Border::TOP, '000000', Border::WIDTH_MEDIUM)))));
        if ($data['inventory']['unpriced'] > 0) {
            $writer->addRow(new Row([new EmptyCell(null, null), new StringCell($this->unpricedHint($data['inventory']['unpriced']), null)]));
        }
        $writer->addRow(new Row([new EmptyCell(null, null), new StringCell('Brutto mit '.ItemType::VAT_PERCENT.' % USt.', null)]));
        $writer->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function unpricedHint(int $count): string
    {
        return $count === 1 ? '1 Artikel ohne Preis – zählt nicht zur Summe (Preis beim Artikel eintragen).' : $count.' Artikel ohne Preis – zählen nicht zur Summe (Preis beim Artikel eintragen).';
    }

    public static function euro(?int $cents): string
    {
        return $cents === null ? '–' : Money::format($cents);
    }
}
