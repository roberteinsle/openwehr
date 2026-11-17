<?php

namespace Modules\Ausruestung\src\Services;

use Illuminate\Support\Collection;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Compartment;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Location;

/**
 * Soll-/Ist-Vergleich der Beladung: per Geräteraum how many items of each category should be there,
 * how many are there and fit for use, and what is there without being planned.
 */
class LoadPlanService
{
    /**
     * @return Collection<int, array{compartment: Compartment, rows: Collection<int, array<string, mixed>>, unplanned: Collection<int, mixed>}>
     */
    public function compare(Location $location): Collection
    {
        $location->load(['compartments.loadPlanItems.category']);

        // On the vehicle means: assigned there and not lent out or retired
        $present = Equipment::with('category')
            ->where('location_id', $location->id)
            ->whereNotIn('status', [EquipmentStatus::Retired->value, EquipmentStatus::Loaned->value])
            ->get()
            ->groupBy('compartment_id');

        /** @var Collection<int, array{compartment: Compartment, rows: Collection<int, array<string, mixed>>, unplanned: Collection<int, mixed>}> $comparison */
        $comparison = $location->compartments->map(function (Compartment $compartment) use ($present) {
            $items = $present->get($compartment->id, collect());
            $planned = $compartment->loadPlanItems->pluck('category_id')->all();

            $rows = $compartment->loadPlanItems->map(function ($plan) use ($items) {
                $ofCategory = $items->where('category_id', $plan->category_id);
                $usable = $ofCategory->filter(fn (Equipment $e) => $e->isUsable());

                return [
                    'category' => $plan->category->name,
                    'target' => $plan->quantity,
                    'present' => $ofCategory->count(),
                    'usable' => $usable->count(),
                    'missing' => max(0, $plan->quantity - $usable->count()),
                    'surplus' => max(0, $ofCategory->count() - $plan->quantity),
                    'problems' => $ofCategory->reject(fn (Equipment $e) => $e->isUsable())
                        ->map(fn (Equipment $e) => $e->code.' '.$e->name.': '.($e->status === EquipmentStatus::Available ? implode(', ', $e->warnings()) : $e->status->label()))
                        ->values(),
                ];
            });

            return [
                'compartment' => $compartment,
                'rows' => $rows,
                'unplanned' => $items->reject(fn (Equipment $e) => in_array($e->category_id, $planned, true))->values(),
            ];
        });

        return $comparison;
    }

    /** Items assigned to the location but to no compartment */
    public function unassigned(Location $location): Collection
    {
        return Equipment::where('location_id', $location->id)->whereNull('compartment_id')
            ->whereNotIn('status', [EquipmentStatus::Retired->value, EquipmentStatus::Loaned->value])
            ->orderBy('code')->get();
    }

    /** All gaps of a location: total missing items */
    public function missingCount(Location $location): int
    {
        return $this->compare($location)->sum(fn ($c) => $c['rows']->sum('missing'));
    }
}
