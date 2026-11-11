<?php

namespace Modules\Kleiderkammer\src\Livewire;

use App\Models\User;
use App\Services\ParentAccess;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Services\EquipmentMessageService;

/** "Meine Ausrüstung": ask the Kleiderwart to wash one or more of the own items, with a reason */
class WashingRequestForm extends Component
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

    /** Quick reasons; the text can be changed or written freely */
    public const REASONS = ['Nach Brandeinsatz – kontaminiert', 'Stark verschmutzt', 'Nach Einsatz mit Gefahrstoffen', 'Regelmäßige Wäsche'];

    /** @var list<string> issued item ids */
    public array $items = [];

    public string $reason = '';

    public ?string $flash = null;

    public function send(EquipmentMessageService $service): void
    {
        $this->resetErrorBag();
        $this->flash = null;
        try {
            $requests = $service->requestWashing($this->owner(), array_map('intval', $this->items), trim($this->reason) === '' ? '' : $this->fromParent($this->reason));
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->flash = 'Wäsche angefordert für '.$requests->pluck('item_label')->implode(', ').'. Der Kleiderwart meldet sich.';
        $this->reset('items', 'reason');
    }

    public function render()
    {
        $issued = IssuedItem::where('user_id', $this->owner()->id)->whereNull('returned_at')
            ->with(['item.itemType', 'item.size', 'itemType', 'size'])->orderByDesc('issued_at')->get();

        return view('kleiderkammer::livewire.washing-request-form', [
            'issued' => $issued,
            'waiting' => EquipmentMessage::where('kind', 'washing')->whereNull('handled_at')->whereIn('issued_item_id', $issued->pluck('id'))->pluck('issued_item_id')->all(),
        ]);
    }
}
