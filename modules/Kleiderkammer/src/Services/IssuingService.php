<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Enums\MemberStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Movement;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Models\Store;

/** Ausgabe and Rücknahme to and from the Kameraden; both are written to the Protokoll (kk_movements) */
class IssuingService
{
    /**
     * Issue a tracked item to a member.
     * The item must be available and not soft-deleted.
     */
    public function issueTracked(User $member, Item $item, string $notes = ''): IssuedItem
    {
        $this->ensureCanReceive($member);

        return DB::transaction(function () use ($member, $item, $notes) {
            $fresh = Item::lockForUpdate()->findOrFail($item->id);

            if ($fresh->status !== ItemStatus::Available) {
                throw new \DomainException("Item {$fresh->code} is not available (status: {$fresh->status->value}).");
            }

            $issuedItem = IssuedItem::create([
                'user_id' => $member->id,
                'item_id' => $fresh->id,
                'quantity' => 1,
                'issued_at' => now(),
                'notes' => $notes,
            ]);

            $fresh->update(['status' => ItemStatus::Issued]);
            Movement::log('ausgabe', $fresh->itemType()->firstOrFail(), 1, $fresh, storeId: $fresh->store_id, user: $member, by: auth()->user(), reason: $notes);

            return $issuedItem;
        });
    }

    /**
     * Issue stock items (non-tracked) to a member.
     * Decreases the stock quantity accordingly: in the given Lager, otherwise in the Artikel's Lager when it
     * has enough, otherwise in the Lager with the most.
     */
    public function issueStock(User $member, ItemType $itemType, ?Size $size, int $quantity, string $notes = '', ?ItemGroup $group = null, ?Store $store = null): IssuedItem
    {
        $this->ensureCanReceive($member);

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($member, $itemType, $size, $quantity, $notes, $group, $store) {
            $rows = Stock::lockForUpdate()
                ->where('item_type_id', $itemType->id)
                ->where('size_id', $size?->id)
                ->where('group_id', $group?->id)
                ->when($store, fn ($q) => $q->where('store_id', $store->id))
                ->get();
            $stock = $rows->first(fn (Stock $row) => $row->store_id === $itemType->store_id && $row->quantity >= $quantity)
                ?? $rows->sortByDesc('quantity')->first();

            $available = $stock ? (int) $stock->quantity : 0;

            if ($available < $quantity) {
                throw new \DomainException("Not enough stock (available: {$available}, requested: {$quantity}).");
            }

            $issuedItem = IssuedItem::create([
                'user_id' => $member->id,
                'item_type_id' => $itemType->id,
                'size_id' => $size?->id,
                'group_id' => $group?->id,
                'store_id' => $stock->store_id,
                'quantity' => $quantity,
                'issued_at' => now(),
                'notes' => $notes,
            ]);

            $stock->decrement('quantity', $quantity);
            Movement::log('ausgabe', $itemType, $quantity, null, $size, $group, $stock->store_id, $member, auth()->user(), $notes);

            return $issuedItem;
        });
    }

    /**
     * Return an issued item or stock quantity.
     * Sets returned_at and, for tracked items, flips the status back to available.
     * For stock items, restores the quantity.
     */
    public function return(IssuedItem $issuedItem): void
    {
        DB::transaction(function () use ($issuedItem) {
            $fresh = IssuedItem::lockForUpdate()->findOrFail($issuedItem->id);

            if ($fresh->returned_at !== null) {
                throw new \DomainException('This record has already been returned.');
            }

            $fresh->update(['returned_at' => now()]);

            if ($fresh->item_id !== null) {
                Item::where('id', $fresh->item_id)->update(['status' => ItemStatus::Available->value]);
                $item = Item::findOrFail($fresh->item_id);
                Movement::log('ruecknahme', $item->itemType()->firstOrFail(), 1, $item, storeId: $item->store_id, user: User::find($fresh->user_id), by: auth()->user());
            } elseif ($fresh->item_type_id !== null) {
                // Back into the Lager it came from
                $type = ItemType::findOrFail($fresh->item_type_id);
                $storeId = $fresh->store_id ?? $type->store_id ?? Store::defaultId();
                Stock::firstOrCreate(
                    ['item_type_id' => $fresh->item_type_id, 'size_id' => $fresh->size_id, 'group_id' => $fresh->group_id, 'store_id' => $storeId],
                    ['quantity' => 0],
                )->increment('quantity', $fresh->quantity);
                Movement::log('ruecknahme', $type, (int) $fresh->quantity, null, $fresh->size()->first(), $fresh->group()->first(), $storeId, User::find($fresh->user_id), auth()->user());
            }
        });
    }

    /**
     * Forcibly return all open items from a member.
     * Typically called when a member leaves (DeactivateMemberAction).
     */
    public function takeBack(User $member): void
    {
        $member->kleiderkammerItems()->with('item')->each(function (IssuedItem $issuedItem) {
            $this->return($issuedItem);
        });
    }

    private function ensureCanReceive(User $member): void
    {
        if ($member->status !== MemberStatus::Active) {
            throw new \DomainException("{$member->name} is not active and cannot receive items.");
        }
    }

    /**
     * Retire a tracked item (worn out / disposed of).
     * The item must not currently be issued.
     */
    public function retire(Item $item, string $notes = ''): void
    {
        DB::transaction(function () use ($item, $notes) {
            $fresh = Item::lockForUpdate()->findOrFail($item->id);

            if ($fresh->status === ItemStatus::Issued) {
                throw new \DomainException("Item {$fresh->code} is currently issued and cannot be retired.");
            }

            $update = ['status' => ItemStatus::Retired];
            if ($notes !== '') {
                $update['notes'] = $notes;
            }

            $fresh->update($update);
            Movement::log('abgang', $fresh->itemType()->firstOrFail(), 1, $fresh, by: auth()->user(), reason: $notes ?: null);
        });
    }
}
