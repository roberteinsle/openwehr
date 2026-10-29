<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Kleiderkammer\src\Filament\Pages\StoreTypePage;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;

class EditStock extends EditRecord
{
    protected static string $resource = StockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Löschen'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return StoreTypePage::getUrl(['type' => $this->getRecord()->getAttribute('item_type_id')]);
    }
}
