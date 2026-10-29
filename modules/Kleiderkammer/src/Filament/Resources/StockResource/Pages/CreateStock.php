<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\StockResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Kleiderkammer\src\Filament\Pages\StoreTypePage;
use Modules\Kleiderkammer\src\Filament\Resources\StockResource;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Store;
use Modules\Kleiderkammer\src\Services\StoreService;

class CreateStock extends CreateRecord
{
    protected static string $resource = StockResource::class;

    /** A new position is a Zugang like in the Lager */
    protected function handleRecordCreation(array $data): Model
    {
        return app(StoreService::class)->receive(ItemType::findOrFail($data['item_type_id']), isset($data['size_id']) ? Size::find($data['size_id']) : null,
            isset($data['group_id']) ? ItemGroup::find($data['group_id']) : null, null, max(1, (int) $data['quantity']),
            isset($data['store_id']) ? Store::find($data['store_id']) : null);
    }

    protected function getRedirectUrl(): string
    {
        return StoreTypePage::getUrl(['type' => $this->getRecord()->getAttribute('item_type_id')]);
    }
}
