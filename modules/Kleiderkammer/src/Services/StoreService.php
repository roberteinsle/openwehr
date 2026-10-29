<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\Disposal;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;

/**
 * The Lager: everything comes in here and is issued from here. Artikeltypen "Einzeln erfasst" (PSA jacket,
 * trousers, boots …) come in piece by piece with their code; all others (gloves …) as a quantity in the
 * Massenbestand. An Ausgang (Ausmusterung) from the Lager or from a Kamerad is kept in kk_disposals with its reason.
 * The Inventar values what the Kleiderkammer owns (Lager and issued) with the prices of the Artikeltypen.
 */
class StoreService
{
    /** Lagereingang: one piece with its code, or a quantity of an Artikeltyp without code */
    public function receive(ItemType $type, ?Size $size, ?ItemGroup $group, ?string $code, int $quantity = 1): Item|Stock
    {
        if ($size && $size->item_type_id !== $type->id) {
            throw ValidationException::withMessages(['size_id' => 'Die Größe gehört nicht zu diesem Artikeltyp.']);
        }
        if ($group && $group->item_type_id !== $type->id) {
            throw ValidationException::withMessages(['group_id' => 'Die Gruppe gehört nicht zu diesem Artikeltyp.']);
        }

        if ($type->is_tracked) {
            $code = trim((string) $code);
            if ($code === '') {
                throw ValidationException::withMessages(['code' => '„'.$type->name.'“ wird einzeln erfasst – bitte den Code des Teils eingeben.']);
            }
            if (Item::withTrashed()->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'Den Code „'.$code.'“ gibt es schon.']);
            }

            return Item::create(['item_type_id' => $type->id, 'size_id' => $size?->id, 'group_id' => $group?->id,
                'code' => mb_substr($code, 0, 50), 'status' => ItemStatus::Available]);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Bitte eine Anzahl ab 1 eingeben.']);
        }

        return DB::transaction(function () use ($type, $size, $group, $quantity) {
            $stock = Stock::lockForUpdate()->where('item_type_id', $type->id)->where('size_id', $size?->id)->where('group_id', $group?->id)->first()
                ?? Stock::create(['item_type_id' => $type->id, 'size_id' => $size?->id, 'group_id' => $group?->id, 'quantity' => 0]);
            $stock->increment('quantity', $quantity);

            return $stock->refresh();
        });
    }

    /** Ausgang from the Lager: one piece by its code, or a quantity of an Artikeltyp without code */
    public function dispose(ItemType $type, ?Size $size, ?ItemGroup $group, ?string $code, int $quantity, string $reason, User $by): Disposal
    {
        $reason = $this->reason($reason);
        if ($type->is_tracked) {
            $item = Item::where('item_type_id', $type->id)->where('code', trim((string) $code))->first();
            if (! $item) {
                throw ValidationException::withMessages(['code' => 'Zu „'.$type->name.'“ gibt es kein Teil mit diesem Code.']);
            }

            return $this->retireItem($item, $reason, $by);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Bitte eine Anzahl ab 1 eingeben.']);
        }

        return DB::transaction(function () use ($type, $size, $group, $quantity, $reason, $by) {
            $stock = Stock::lockForUpdate()->where('item_type_id', $type->id)->where('size_id', $size?->id)->where('group_id', $group?->id)->first();
            $available = $stock ? (int) $stock->quantity : 0;
            if ($stock === null || $available < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Im Lager sind nur '.$available.' davon.']);
            }
            $stock->decrement('quantity', $quantity);

            return Disposal::create(['item_type_id' => $type->id, 'size_id' => $size?->id, 'group_id' => $group?->id,
                'quantity' => $quantity, 'reason' => $reason, 'user_id' => $by->id]);
        });
    }

    /** Ausmustern of one piece in the Lager (not one a Kamerad has: that goes through disposeIssued) */
    public function retireItem(Item $item, string $reason, User $by): Disposal
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($item, $reason, $by) {
            $fresh = Item::lockForUpdate()->findOrFail($item->id);
            if ($fresh->status === ItemStatus::Issued) {
                throw ValidationException::withMessages(['code' => 'Das Teil '.$fresh->code.' ist ausgegeben – bitte beim Kameraden unter „Ausrüstung“ ausmustern.']);
            }
            if ($fresh->status === ItemStatus::Retired) {
                throw ValidationException::withMessages(['code' => 'Das Teil '.$fresh->code.' ist schon ausgemustert.']);
            }
            $fresh->update(['status' => ItemStatus::Retired, 'notes' => trim(($fresh->notes ? $fresh->notes."\n" : '').'Ausgemustert: '.$reason)]);

            return Disposal::create(['item_type_id' => $fresh->item_type_id, 'item_id' => $fresh->id, 'size_id' => $fresh->size_id,
                'group_id' => $fresh->group_id, 'quantity' => 1, 'reason' => $reason, 'user_id' => $by->id]);
        });
    }

    /** Ausmustern from a Kamerad's Ausrüstung: the loan ends, nothing goes back into the Lager */
    public function disposeIssued(IssuedItem $issued, string $reason, User $by): Disposal
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($issued, $reason, $by) {
            $fresh = IssuedItem::lockForUpdate()->findOrFail($issued->id);
            if ($fresh->returned_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Das ist schon zurückgegeben.']);
            }
            $fresh->update(['returned_at' => now(), 'notes' => trim(($fresh->notes ? $fresh->notes."\n" : '').'Ausgemustert: '.$reason)]);
            $item = $fresh->item_id ? Item::lockForUpdate()->find($fresh->item_id) : null;
            $item?->update(['status' => ItemStatus::Retired, 'notes' => trim(($item->notes ? $item->notes."\n" : '').'Ausgemustert: '.$reason)]);

            return Disposal::create(['item_type_id' => $item ? $item->item_type_id : $fresh->item_type_id, 'item_id' => $item?->id,
                'size_id' => $item ? $item->size_id : $fresh->size_id, 'group_id' => $item ? $item->group_id : $fresh->group_id,
                'quantity' => $item ? 1 : (int) $fresh->quantity, 'reason' => $reason, 'from_user_id' => $fresh->user_id, 'user_id' => $by->id]);
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Bitte einen Grund angeben (z. B. verschlissen, defekt).']);
        }

        return mb_substr($reason, 0, 300);
    }

    /**
     * The Lager by Artikeltyp, group and size: how many are in the Lager and how many are issued.
     *
     * @return Collection<string, array{key: string, type_id: int, type: string, category: string, group: string, size: string, tracked: bool, in_store: int, issued: int}>
     */
    public function levels(): Collection
    {
        $rows = collect();
        $add = function (ItemType $type, ?int $groupId, ?int $sizeId, string $group, string $size, int $inStore, int $issued) use ($rows) {
            $key = $type->id.'-'.($groupId ?? 0).'-'.($sizeId ?? 0);
            $row = $rows->get($key) ?? ['key' => $key, 'type_id' => $type->id, 'type' => $type->name, 'category' => (string) $type->category?->name,
                'group' => $group, 'size' => $size, 'tracked' => (bool) $type->is_tracked, 'in_store' => 0, 'issued' => 0];
            $row['in_store'] += $inStore;
            $row['issued'] += $issued;
            $rows->put($key, $row);
        };

        foreach ($this->types() as $type) {
            if ($type->is_tracked) {
                foreach (Item::where('item_type_id', $type->id)->whereIn('status', [ItemStatus::Available->value, ItemStatus::Issued->value])->with(['size', 'group'])->get() as $item) {
                    $add($type, $item->group_id, $item->size_id, (string) $item->group?->name, (string) $item->size?->name,
                        $item->status === ItemStatus::Available ? 1 : 0, $item->status === ItemStatus::Issued ? 1 : 0);
                }

                continue;
            }
            foreach (Stock::where('item_type_id', $type->id)->with(['size', 'group'])->get() as $stock) {
                $add($type, $stock->group_id, $stock->size_id, (string) $stock->group?->name, (string) $stock->size?->name, max(0, (int) $stock->quantity), 0);
            }
            foreach (IssuedItem::where('item_type_id', $type->id)->whereNull('item_id')->whereNull('returned_at')->with(['size', 'group'])->get() as $issued) {
                $add($type, $issued->group_id, $issued->size_id, (string) $issued->group?->name, (string) $issued->size?->name, 0, (int) $issued->quantity);
            }
        }

        return $rows;
    }

    /**
     * The Lager list: one row per Artikeltyp (also those with nothing in it), its groups and sizes summed up; Gesamt = Lager + ausgegeben.
     *
     * @return Collection<string, array{key: string, type_id: int, type: string, category: string, tracked: bool, in_store: int, issued: int, total: int}>
     */
    public function byType(): Collection
    {
        $levels = $this->levels()->groupBy('type_id');
        $rows = collect();
        foreach ($this->types() as $type) {
            $own = $levels->get($type->id, collect());
            $rows->put('t'.$type->id, ['key' => 't'.$type->id, 'type_id' => $type->id, 'type' => $type->name, 'category' => (string) $type->category?->name,
                'tracked' => (bool) $type->is_tracked, 'in_store' => (int) $own->sum('in_store'), 'issued' => (int) $own->sum('issued'), 'total' => (int) $own->sum('in_store') + (int) $own->sum('issued')]);
        }

        return $rows;
    }

    /**
     * Inventar: per Artikeltyp the number the Kleiderkammer owns (in the Lager and issued) with net and gross prices and sums (cent).
     *
     * @return array{rows: list<array{nr: int, name: string, quantity: int, net: ?int, net_sum: ?int, gross: ?int, gross_sum: ?int}>, net_total: int, gross_total: int, quantity_total: int, unpriced: int}
     */
    public function inventory(): array
    {
        $rows = [];
        $netTotal = $grossTotal = $quantityTotal = $unpriced = 0;
        foreach ($this->types() as $type) {
            // Everything the Kleiderkammer owns: in the Lager and issued to Kameraden; retired pieces are gone
            $quantity = $type->is_tracked
                ? Item::where('item_type_id', $type->id)->whereIn('status', [ItemStatus::Available->value, ItemStatus::Issued->value])->count()
                : max(0, (int) Stock::where('item_type_id', $type->id)->sum('quantity'))
                    + (int) IssuedItem::where('item_type_id', $type->id)->whereNull('item_id')->whereNull('returned_at')->sum('quantity');
            if ($quantity === 0) {
                continue;
            }
            $net = $type->price_net_cents;
            $gross = $type->priceGrossCents();
            $rows[] = ['nr' => count($rows) + 1, 'name' => $type->name, 'quantity' => $quantity, 'net' => $net,
                'net_sum' => $net === null ? null : $net * $quantity, 'gross' => $gross, 'gross_sum' => $gross === null ? null : $gross * $quantity];
            $netTotal += $net === null ? 0 : $net * $quantity;
            $grossTotal += $gross === null ? 0 : $gross * $quantity;
            $quantityTotal += $quantity;
            $unpriced += $net === null ? 1 : 0;
        }

        return ['rows' => $rows, 'net_total' => $netTotal, 'gross_total' => $grossTotal, 'quantity_total' => $quantityTotal, 'unpriced' => $unpriced];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ItemType> in the order of the catalogue */
    private function types(): \Illuminate\Database\Eloquent\Collection
    {
        return ItemType::with('category')->get()
            ->sortBy(fn (ItemType $t) => [$t->category ? $t->category->sort_order : PHP_INT_MAX, $t->sort_order, $t->name])->values();
    }
}
