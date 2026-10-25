<?php

namespace App\Filament\Resources\ContactResource\Pages;

use App\Filament\Resources\ContactGroupResource;
use App\Filament\Resources\ContactResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('groups')->label('Gruppen')->icon('heroicon-o-rectangle-group')->color('gray')->url(ContactGroupResource::getUrl('index')),
            Actions\CreateAction::make()->label('Ansprechpartner anlegen'),
        ];
    }
}
