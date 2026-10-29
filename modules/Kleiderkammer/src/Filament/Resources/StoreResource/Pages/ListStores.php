<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StoreResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Kleiderkammer\src\Filament\Resources\StoreResource;

class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Lager anlegen')];
    }
}
