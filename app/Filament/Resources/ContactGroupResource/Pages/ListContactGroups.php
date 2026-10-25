<?php

namespace App\Filament\Resources\ContactGroupResource\Pages;

use App\Filament\Resources\ContactGroupResource;
use App\Filament\Resources\ContactResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContactGroups extends ListRecords
{
    protected static string $resource = ContactGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('contacts')->label('Zu den Ansprechpartnern')->icon('heroicon-o-arrow-left')->color('gray')->url(ContactResource::getUrl('index')),
            Actions\CreateAction::make()->label('Gruppe anlegen'),
        ];
    }
}
