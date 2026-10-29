<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\ItemResource\Pages;

use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Modules\Kleiderkammer\src\Filament\Pages\StoreTypePage;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Services\CodeGenerator;
use Modules\Kleiderkammer\src\Services\CodeResolver;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\StoreService;

class CreateItem extends CreateRecord
{
    protected static string $resource = ItemResource::class;

    private ?int $issueTo = null;

    public ?string $scannedCode = null;

    public function getSubheading(): ?string
    {
        return $this->scannedCode !== null
            ? "Der Code {$this->scannedCode} ist noch unbekannt. Lege das Teil jetzt an."
            : null;
    }

    protected function afterFill(): void
    {
        $code = trim((string) request()->query('code', ''));
        if ($code === '') {
            return;
        }

        $this->scannedCode = $code;
        $this->form->fill([
            'code' => $code,
            'item_type_id' => app(CodeResolver::class)->guessItemType($code)?->id,
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->issueTo = isset($data['issue_to']) ? (int) $data['issue_to'] : null;
        unset($data['issue_to']);

        // Auto-generate code if not set and item type is known
        if (empty($data['code']) && ! empty($data['item_type_id'])) {
            $type = ItemType::find($data['item_type_id']);
            if ($type) {
                $data['code'] = app(CodeGenerator::class)->generate($type);
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        if ($record instanceof Item) {
            app(StoreService::class)->logReceived($record);
        }

        if ($this->issueTo === null) {
            return;
        }

        $member = User::findOrFail($this->issueTo);
        app(IssuingService::class)->issueTracked($member, $this->record);

        Notification::make()->title("Teil an {$member->name} ausgegeben.")->success()->send();
    }

    protected function getRedirectUrl(): string
    {
        return StoreTypePage::getUrl(['type' => $this->getRecord()->getAttribute('item_type_id')]);
    }
}
