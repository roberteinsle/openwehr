<?php

namespace Modules\Kleiderkammer\src\Livewire;

use App\Models\User;
use App\Services\ParentAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Services\EquipmentMessageService;

/** "Meine Ausrüstung": write to the Kleiderwart about one issued item */
class EquipmentMessageForm extends Component
{
    /** Eltern-Bereich: the child whose items a parent sees (null = the signed-in Kamerad's own) */
    #[Locked]
    public ?int $childId = null;

    /** Whose items: the Kamerad, or the child of the signed-in parent */
    private function owner(): User
    {
        $owner = app(ParentAccess::class)->memberFor($this->childId);
        abort_unless($owner !== null, 404);

        return $owner;
    }

    /** A parent's message names them, so the Kleiderwart knows who wrote */
    private function fromParent(string $text): string
    {
        return $this->childId === null ? $text : mb_substr($text.' (von '.app(ParentAccess::class)->name().', erziehungsberechtigt)', 0, 2000);
    }

    #[Locked]
    public int $issuedItemId;

    public string $body = '';

    public bool $sent = false;

    private function issued(): IssuedItem
    {
        return IssuedItem::where('user_id', $this->owner()->id)
            ->whereNull('returned_at')
            ->findOrFail($this->issuedItemId);
    }

    public function send(EquipmentMessageService $service): void
    {
        $this->validate(
            ['body' => ['required', 'string', 'max:2000']],
            ['body.required' => 'Bitte schreib eine Nachricht.', 'body.max' => 'Die Nachricht ist zu lang.'],
        );

        $service->send($this->owner(), $this->issued(), $this->fromParent(trim($this->body)));

        $this->reset('body');
        $this->sent = true;
    }

    public function render()
    {
        return view('kleiderkammer::livewire.equipment-message-form', [
            'messages' => EquipmentMessage::where('user_id', $this->owner()->id)
                ->where('issued_item_id', $this->issuedItemId)
                ->latest()
                ->get(),
        ]);
    }
}
