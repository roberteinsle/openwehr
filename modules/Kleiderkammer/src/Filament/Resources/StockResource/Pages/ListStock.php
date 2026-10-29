<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Kleiderkammer\src\Filament\Pages\StorePage;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;

class ListStock extends ListRecords
{
    protected static string $resource = StockResource::class;

    // Everything goes through the Lager now; old links to this list end up there
    public function mount(): void
    {
        $this->redirect(StorePage::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Bestandsposition anlegen'),
        ];
    }
}
