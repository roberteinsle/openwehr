<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource;

class EditItemCategory extends EditRecord
{
    protected static string $resource = ItemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Löschen')
                ->before(function (Actions\DeleteAction $action) {
                    if ($this->record->itemTypes()->exists()) {
                        Notification::make()
                            ->title('Kategorie kann nicht gelöscht werden')
                            ->body('Es existieren Artikel in dieser Kategorie.')
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
