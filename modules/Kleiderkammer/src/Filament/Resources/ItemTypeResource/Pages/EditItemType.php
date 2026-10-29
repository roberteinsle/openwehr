<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource;
use Modules\Kleiderkammer\src\Models\ItemType;

class EditItemType extends EditRecord
{
    protected static string $resource = ItemTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('delete')
                ->label('Löschen')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalHeading('Artikel löschen?')
                ->action(function () {
                    $record = $this->getRecord();
                    if (! $record instanceof ItemType || ! $record->deleteIfUnused()) {
                        Notification::make()->title('Artikel kann nicht gelöscht werden')
                            ->body('Es gibt Teile, Bestand oder Ausgabevorgänge zu diesem Artikel.')->danger()->send();

                        return;
                    }
                    Notification::make()->title('Artikel gelöscht.')->success()->send();
                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
