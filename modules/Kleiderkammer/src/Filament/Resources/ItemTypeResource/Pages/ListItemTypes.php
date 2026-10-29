<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource;

class ListItemTypes extends ListRecords
{
    protected static string $resource = ItemTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Artikel anlegen'),
        ];
    }
}
