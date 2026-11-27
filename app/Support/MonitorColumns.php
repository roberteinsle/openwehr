<?php

namespace App\Support;

use App\Models\Group;
use Illuminate\Support\Collection;

/**
 * Splits upcoming items (Dienste, Termine) into one column per Abteilung for the Wachen-Monitor,
 * e.g. Aktive – Jugendfeuerwehr – Musikzug. Items for the whole Wehr appear in every column.
 */
final class MonitorColumns
{
    /** Columns per field size; more Abteilungen than this are left out */
    public const MAX = ['full' => 4, 'half' => 3, 'third' => 2, 'quarter' => 3];

    /** Items per column and field size */
    public const ITEMS = ['full' => 8, 'half' => 5, 'third' => 6, 'quarter' => 3];

    /**
     * @template T
     *
     * @param  Collection<int, T>  $items  in order of time
     * @param  callable(T): ?list<int>  $groupIdsOf  the Abteilungen of an item, null = the whole Wehr
     * @param  array{size: string, group_ids: list<int>}  $options
     * @return list<array{group: ?Group, items: list<array{item: T, all: bool}>}> group null = one column "Ganze Wehr"
     */
    public static function build(Collection $items, callable $groupIdsOf, array $options): array
    {
        $size = $options['size'];
        $chosen = $options['group_ids'];
        $perColumn = self::ITEMS[$size] ?? 3;

        $rows = $items->map(fn ($item) => ['item' => $item, 'groups' => $groupIdsOf($item)])->values();
        $groups = MonitorSettings::groups($chosen);

        // Without a choice: only the Abteilungen that have something of their own
        if ($chosen === []) {
            $own = $rows->pluck('groups')->filter()->flatten()->unique()->all();
            $groups = $groups->filter(fn (Group $g) => in_array($g->id, $own, true))->values();
        }
        $groups = $groups->take(self::MAX[$size] ?? 3);

        if ($groups->isEmpty()) {
            return $rows->isEmpty() ? [] : [[
                'group' => null,
                'items' => $rows->take($perColumn)->map(fn ($r) => ['item' => $r['item'], 'all' => $r['groups'] === null])->values()->all(),
            ]];
        }

        return $groups->map(fn (Group $group) => [
            'group' => $group,
            'items' => $rows
                ->filter(fn ($r) => $r['groups'] === null || in_array($group->id, $r['groups'], true))
                ->take($perColumn)
                ->map(fn ($r) => ['item' => $r['item'], 'all' => $r['groups'] === null])
                ->values()->all(),
        ])->values()->all();
    }
}
