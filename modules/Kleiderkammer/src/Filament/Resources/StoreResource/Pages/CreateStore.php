<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StoreResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Kleiderkammer\src\Filament\Resources\StoreResource;
use Modules\Kleiderkammer\src\Models\Store;

class CreateStore extends CreateRecord
{
    protected static string $resource = StoreResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['sort_order' => (int) Store::max('sort_order') + 1];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
