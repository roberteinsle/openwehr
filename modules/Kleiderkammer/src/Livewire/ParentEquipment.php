<?php

namespace Modules\Kleiderkammer\src\Livewire;

use App\Services\ParentAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Kleiderkammer\src\Models\IssuedItem;

/** Eltern-Bereich: what is issued to the child (T-Shirts, Pullover, Jacke …), with Wäsche and Mängel like "Meine Ausrüstung" */
class ParentEquipment extends Component
{
    #[Locked]
    public int $childId;

    public function mount(): void
    {
        abort_unless(app(ParentAccess::class)->child($this->childId) !== null, 404);
    }

    public function hydrate(): void
    {
        $this->mount();
    }

    public function render()
    {
        return view('kleiderkammer::livewire.parent-equipment', [
            'items' => IssuedItem::where('user_id', $this->childId)->whereNull('returned_at')
                ->with(['item.itemType', 'item.size', 'item.group', 'itemType', 'size', 'group', 'messages'])
                ->orderByDesc('issued_at')->get(),
        ]);
    }
}
