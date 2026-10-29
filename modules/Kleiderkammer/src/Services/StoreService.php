<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Movement;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Models\Store;

/**
 * The Lager (one or several, kk_stores): everything comes in here and is issued from here. Artikel "Einzeln
 * erfasst" (PSA jacket, trousers, boots …) come in piece by piece with their code; all others (gloves …) as
 * a quantity. Zugang, Abgang (Ausmusterung, from the Lager or from a Kamerad) and Korrektur are written to
 * the Protokoll (kk_movements), Ausgabe and Rücknahme by the IssuingService. The Inventar values what the
 * Kleiderkammer owns (in every Lager and issued) with the prices of the Artikel.
 */
class StoreService
{
    /** Lagereingang: one piece with its code, or a quantity of an Artikel without code; into the given Lager or the Artikel's */
    public function receive(ItemType $type, ?Size $size, ?ItemGroup $group, ?string $code, int $quantity = 1, ?Store $store = null, ?User $by = null): Item|Stock
    {
        if ($size && $size->item_type_id !== $type->id) {
            throw ValidationException::withMessages(['size_id' => 'Die Größe gehört nicht zu diesem Artikel.']);
        }
        if ($group && $group->item_type_id !== $type->id) {
            throw ValidationException::withMessages(['group_id' => 'Die Gruppe gehört nicht zu diesem Artikel.']);
        }
        $storeId = $store->id ?? $type->store_id ?? Store::defaultId();
        $by ??= auth()->user();

        if ($type->is_tracked) {
            $code = trim((string) $code);
            if ($code === '') {
                throw ValidationException::withMessages(['code' => '„'.$type->name.'“ wird einzeln erfasst – bitte den Code des Teils eingeben.']);
            }
            if (Item::withTrashed()->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'Den Code „'.$code.'“ gibt es schon.']);
            }

            return DB::transaction(function () use ($type, $size, $group, $code, $storeId, $by) {
                $item = Item::create(['item_type_id' => $type->id, 'size_id' => $size?->id, 'group_id' => $group?->id,
                    'store_id' => $storeId, 'code' => mb_substr($code, 0, 50), 'status' => ItemStatus::Available]);
                Movement::log('zugang', $type, 1, $item, $size, $group, $storeId, null, $by);

                return $item;
            });
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Bitte eine Anzahl ab 1 eingeben.']);
        }

        return DB::transaction(function () use ($type, $size, $group, $quantity, $storeId, $by) {
            $stock = $this->stockRow($type, $size, $group, $storeId);
            $stock->increment('quantity', $quantity);
            Movement::log('zugang', $type, $quantity, null, $size, $group, $storeId, null, $by);

            return $stock->refresh();
        });
    }

    /** A piece that came in another way (scanner "Einzelteil anlegen") gets its Zugang in the Protokoll too */
    public function logReceived(Item $item, ?User $by = null): void
    {
        Movement::log('zugang', $item->itemType()->firstOrFail(), 1, $item, by: $by ?? auth()->user());
    }

    /** Abgang from a Lager: one piece by its code, or a quantity of an Artikel without code */
    public function dispose(ItemType $type, ?Size $size, ?ItemGroup $group, ?string $code, int $quantity, string $reason, User $by, ?Store $store = null): Movement
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
        $storeId = $store->id ?? $type->store_id ?? Store::defaultId();

        return DB::transaction(function () use ($type, $size, $group, $quantity, $reason, $by, $storeId) {
            $stock = Stock::lockForUpdate()->where('item_type_id', $type->id)->where('size_id', $size?->id)->where('group_id', $group?->id)
                ->where('store_id', $storeId)->first();
            $available = $stock ? (int) $stock->quantity : 0;
            if ($stock === null || $available < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Im Lager sind nur '.$available.' davon.']);
            }
            $stock->decrement('quantity', $quantity);

            return Movement::log('abgang', $type, $quantity, null, $size, $group, $storeId, null, $by, $reason);
        });
    }

    /** Ausmustern of one piece in a Lager (not one a Kamerad has: that goes through disposeIssued) */
    public function retireItem(Item $item, string $reason, User $by): Movement
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

            return Movement::log('abgang', $fresh->itemType()->firstOrFail(), 1, $fresh, by: $by, reason: $reason);
        });
    }

    /** Ausmustern from a Kamerad's Ausrüstung: the loan ends, nothing goes back into the Lager */
    public function disposeIssued(IssuedItem $issued, string $reason, User $by): Movement
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
            $type = $item ? $item->itemType()->firstOrFail() : ItemType::findOrFail($fresh->item_type_id);

            return Movement::log('abgang', $type, $item ? 1 : (int) $fresh->quantity, $item, $item ? null : $fresh->size()->first(),
                $item ? null : $fresh->group()->first(), $item ? $item->store_id : $fresh->store_id, User::find($fresh->user_id), $by, $reason);
        });
    }

    /** "Korrigieren" of a stock row: the counted quantity; the difference goes into the Protokoll */
    public function correct(Stock $stock, int $quantity, User $by, ?string $reason = null): void
    {
        if ($quantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'Die Anzahl kann nicht negativ sein.']);
        }
        DB::transaction(function () use ($stock, $quantity, $by, $reason) {
            $fresh = Stock::lockForUpdate()->findOrFail($stock->id);
            $diff = $quantity - (int) $fresh->quantity;
            if ($diff === 0) {
                return;
            }
            $fresh->update(['quantity' => $quantity]);
            Movement::log('korrektur', $fresh->itemType()->firstOrFail(), $diff, null, $fresh->size()->first(), $fresh->group()->first(), $fresh->store_id, null, $by, $reason ?: 'Bestand korrigiert');
        });
    }

    private function stockRow(ItemType $type, ?Size $size, ?ItemGroup $group, int $storeId): Stock
    {
        return Stock::lockForUpdate()->where('item_type_id', $type->id)->where('size_id', $size?->id)->where('group_id', $group?->id)->where('store_id', $storeId)->first()
            ?? Stock::create(['item_type_id' => $type->id, 'size_id' => $size?->id, 'group_id' => $group?->id, 'store_id' => $storeId, 'quantity' => 0]);
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
     * The Lager by Artikel, Lager, group and size: how many are in the Lager and how many are issued from it.
     *
     * @return Collection<string, array{key: string, type_id: int, type: string, category: string, store: string, group: string, size: string, tracked: bool, in_store: int, issued: int}>
     */
    public function levels(?int $storeId = null): Collection
    {
        $stores = Store::pluck('name', 'id');
        $rows = collect();
        $add = function (ItemType $type, ?int $store, ?int $groupId, ?int $sizeId, string $group, string $size, int $inStore, int $issued) use ($rows, $stores, $storeId) {
            if ($storeId !== null && $store !== $storeId) {
                return;
            }
            $key = $type->id.'-'.($store ?? 0).'-'.($groupId ?? 0).'-'.($sizeId ?? 0);
            $row = $rows->get($key) ?? ['key' => $key, 'type_id' => $type->id, 'type' => $type->name, 'category' => (string) $type->category?->name,
                'store' => (string) $stores->get($store), 'group' => $group, 'size' => $size, 'tracked' => (bool) $type->is_tracked, 'in_store' => 0, 'issued' => 0];
            $row['in_store'] += $inStore;
            $row['issued'] += $issued;
            $rows->put($key, $row);
        };

        foreach ($this->types() as $type) {
            if ($type->is_tracked) {
                foreach (Item::where('item_type_id', $type->id)->whereIn('status', [ItemStatus::Available->value, ItemStatus::Issued->value])->with(['size', 'group'])->get() as $item) {
                    $add($type, $item->store_id, $item->group_id, $item->size_id, (string) $item->group?->name, (string) $item->size?->name,
                        $item->status === ItemStatus::Available ? 1 : 0, $item->status === ItemStatus::Issued ? 1 : 0);
                }

                continue;
            }
            foreach (Stock::where('item_type_id', $type->id)->with(['size', 'group'])->get() as $stock) {
                $add($type, $stock->store_id, $stock->group_id, $stock->size_id, (string) $stock->group?->name, (string) $stock->size?->name, max(0, (int) $stock->quantity), 0);
            }
            foreach (IssuedItem::where('item_type_id', $type->id)->whereNull('item_id')->whereNull('returned_at')->with(['size', 'group'])->get() as $issued) {
                $add($type, $issued->store_id, $issued->group_id, $issued->size_id, (string) $issued->group?->name, (string) $issued->size?->name, 0, (int) $issued->quantity);
            }
        }

        return $rows;
    }

    /**
     * The Lager list: one row per Artikel (also those with nothing in it); Gesamt = Lager + ausgegeben.
     *
     * @return Collection<string, array{key: string, type_id: int, type: string, category: string, tracked: bool, in_store: int, issued: int, total: int}>
     */
    public function byType(?int $storeId = null): Collection
    {
        $levels = $this->levels($storeId)->groupBy('type_id');
        $rows = collect();
        foreach ($this->types() as $type) {
            $own = $levels->get($type->id, collect());
            $rows->put('t'.$type->id, ['key' => 't'.$type->id, 'type_id' => $type->id, 'type' => $type->name, 'category' => (string) $type->category?->name,
                'tracked' => (bool) $type->is_tracked, 'in_store' => (int) $own->sum('in_store'), 'issued' => (int) $own->sum('issued'), 'total' => (int) $own->sum('in_store') + (int) $own->sum('issued')]);
        }

        return $rows;
    }

    /**
     * Inventar: per Artikel the number the Kleiderkammer owns (in every Lager and issued) with net and gross prices and sums (cent).
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

    /** @return EloquentCollection<int, ItemType> in alphabetical order */
    private function types(): EloquentCollection
    {
        return ItemType::with('category')->get()->sortBy(fn (ItemType $t) => mb_strtolower($t->name))->values();
    }
}
