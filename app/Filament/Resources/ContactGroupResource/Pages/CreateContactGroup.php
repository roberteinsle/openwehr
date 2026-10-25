<?php

namespace App\Filament\Resources\ContactGroupResource\Pages;

use App\Filament\Resources\ContactGroupResource;
use App\Models\ContactGroup;
use Filament\Resources\Pages\CreateRecord;

class CreateContactGroup extends CreateRecord
{
    protected static string $resource = ContactGroupResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['sort_order' => (int) ContactGroup::max('sort_order') + 1];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
