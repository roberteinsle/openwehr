<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Filament\Pages\StoreTypePage;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource;

class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A piece a Kamerad has is taken back or retired first, so no issue points at an archived piece
            Actions\DeleteAction::make()->label('Archivieren')
                ->before(function (Actions\DeleteAction $action) {
                    if ($this->getRecord()->getAttribute('status') === ItemStatus::Issued) {
                        Notification::make()->danger()->title('Teil ist ausgegeben')
                            ->body('Erst beim Kameraden zurücknehmen oder ausmustern, dann archivieren.')->send();
                        $action->cancel();
                    }
                }),
            Actions\RestoreAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return StoreTypePage::getUrl(['type' => $this->getRecord()->getAttribute('item_type_id')]);
    }
}
