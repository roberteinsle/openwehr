<?php

namespace Modules\Kleiderkammer\src\Services;

use Illuminate\Support\Collection;
use Modules\Kleiderkammer\src\Enums\ItemStatus;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Stock;

class InventoryReport
{
    /**
     * Counts per item type for tracked items.
     * Returns a collection of objects with:
     *   item_type_id, name, total, available, issued, retired
     */
    public function trackedByType(): Collection
    {
        return ItemType::where('is_tracked', true)
            ->with(['items' => fn ($q) => $q->withTrashed()])
            ->get()
            ->map(fn (ItemType $type) => (object) [
                'item_type_id' => $type->id,
                'name' => $type->name,
                'total' => $type->items->count(),
                'available' => $type->items->where('status', ItemStatus::Available)->count(),
                'issued' => $type->items->where('status', ItemStatus::Issued)->count(),
                'retired' => $type->items->where('status', ItemStatus::Retired)->count(),
            ]);
    }

    /**
     * Stock quantities for non-tracked item types by type, group and size (all Lager together).
     */
    public function stockByType(): Collection
    {
        return Stock::with(['itemType', 'size', 'group'])
            ->whereHas('itemType', fn ($q) => $q->where('is_tracked', false))
            ->get()
            ->groupBy(fn (Stock $row) => $row->item_type_id.'-'.$row->group_id.'-'.$row->size_id)
            ->map(fn ($rows) => (object) [
                'item_type_id' => $rows->first()->item_type_id,
                'name' => $rows->first()->itemType?->name,
                'size' => collect([$rows->first()->group?->name, $rows->first()->size?->name])->filter()->implode(' · ') ?: null,
                'quantity' => (int) $rows->sum('quantity'),
            ])
            ->values();
    }

    /**
     * Open issuance records (items still out with members), the latest first.
     */
    public function openLoans(): Collection
    {
        return IssuedItem::with(['user', 'item.itemType', 'itemType', 'size'])
            ->whereNull('returned_at')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Summary counts: total tracked items, available, issued, retired; total stock rows.
     *
     * @return array{tracked_total: int, available: int, issued: int, retired: int, open_loans: int}
     */
    public function summary(): array
    {
        return [
            'tracked_total' => $this->trackedByType()->sum('total'),
            'available' => $this->trackedByType()->sum('available'),
            'issued' => $this->trackedByType()->sum('issued'),
            'retired' => $this->trackedByType()->sum('retired'),
            'open_loans' => IssuedItem::whereNull('returned_at')->count(),
        ];
    }
}
