<?php

namespace Modules\Kleiderkammer\src\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Kleiderkammer\src\Services\InventoryReport;

/** The former Bestandsbericht on the Kleiderkammer Übersicht: Artikel mit Code, Artikel mit Menge and the latest Ausleihen */
class ReportWidget extends Widget
{
    public const ROWS = 5;

    protected string $view = 'kleiderkammer::filament.widgets.report';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $report = app(InventoryReport::class);

        // Five each: the Artikel with code with the most pieces, the Artikel with quantity that run lowest, the latest Ausleihen
        return [
            'trackedByType' => $report->trackedByType()->sortByDesc('total')->take(self::ROWS)->values(),
            'stockByType' => $report->stockByType()->sortBy('quantity')->take(self::ROWS)->values(),
            'openLoans' => $report->openLoans()->take(self::ROWS),
        ];
    }
}
