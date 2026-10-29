<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StoreResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Kleiderkammer\src\Filament\Resources\StoreResource;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
