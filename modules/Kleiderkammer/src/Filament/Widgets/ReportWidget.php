<?php

namespace Modules\Kleiderkammer\src\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Kleiderkammer\src\Services\InventoryReport;

/** The former Bestandsbericht on the Kleiderkammer Übersicht: Einzelteile by type, Massenbestand and open loans */
class ReportWidget extends Widget
{
    protected string $view = 'kleiderkammer::filament.widgets.report';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $report = app(InventoryReport::class);

        return [
            'trackedByType' => $report->trackedByType(),
            'stockByType' => $report->stockByType(),
            'openLoans' => $report->openLoans(),
        ];
    }
}
