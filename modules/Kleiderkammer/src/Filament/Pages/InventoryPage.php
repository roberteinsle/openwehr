<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Services\InventoryExport;

/** Inventar: the Lager valued with the prices of the Artikeltypen, to print (PDF) or export (Excel), both DIN A4 */
class InventoryPage extends Page
{
    use KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/inventar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Inventar';

    protected static ?string $title = 'Inventar';

    protected static ?int $navigationSort = 9;

    protected string $view = 'kleiderkammer::filament.pages.inventory';

    protected function getViewData(): array
    {
        return app(InventoryExport::class)->data(auth()->user());
    }

    protected function getHeaderActions(): array
    {
        $export = app(InventoryExport::class);

        return [
            Action::make('pdf')->label('PDF drucken')->icon('heroicon-o-printer')
                ->action(fn () => response()->streamDownload(fn () => print ($export->pdf(auth()->user())), $export->filename('pdf'), ['Content-Type' => 'application/pdf'])),
            Action::make('xlsx')->label('Excel')->icon('heroicon-o-table-cells')->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print ($export->xlsx(auth()->user())), $export->filename('xlsx'),
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])),
        ];
    }
}
