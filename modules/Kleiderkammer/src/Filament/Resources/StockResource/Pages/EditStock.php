<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Kleiderkammer\src\Filament\Pages\StoreTypePage;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\StoreService;

class EditStock extends EditRecord
{
    protected static string $resource = StockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Löschen')->visible(fn () => (int) $this->getRecord()->getAttribute('quantity') === 0),
        ];
    }

    /** Only the quantity changes here, as a Korrektur in the Protokoll */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record instanceof Stock) {
            app(StoreService::class)->correct($record, (int) $data['quantity'], auth()->user(), $data['reason'] ?? null);
        }

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return StoreTypePage::getUrl(['type' => $this->getRecord()->getAttribute('item_type_id')]);
    }
}
