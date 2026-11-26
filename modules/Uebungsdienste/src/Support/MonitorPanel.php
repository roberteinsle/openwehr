<?php

namespace Modules\Uebungsdienste\src\Support;

use App\Support\MonitorColumns;
use Illuminate\Contracts\View\View;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\ServicePlan;

/**
 * "Nächste Dienste je Abteilung" on the Wachen-Monitor: the coming Übungsdienste with topic, side by
 * side for each Abteilung. No responsible people: the screen hangs in the station.
 */
final class MonitorPanel
{
    /** @param  array{size: string, group_ids: list<int>}  $options */
    public static function render(array $options): View
    {
        $now = now();
        $services = Service::with('place')
            ->whereDate('date', '>=', ServicePlan::today())
            ->orderBy('date')->orderBy('starts_at')
            ->limit(200)->get()
            // Today's Dienst stays until it is over
            ->reject(fn (Service $s) => ($period = $s->period()) && $period[1]->lt($now))
            ->values();

        return view('uebungsdienste::monitor.groups', [
            'columns' => MonitorColumns::build($services, fn (Service $s) => self::groupIds($s), $options),
            'size' => $options['size'],
            'today' => now('Europe/Berlin')->toDateString(),
            'tomorrow' => now('Europe/Berlin')->addDay()->toDateString(),
        ]);
    }

    /** @return ?list<int> the Abteilungen a Dienst is for, null = the whole Wehr */
    public static function groupIds(Service $service): ?array
    {
        $ids = array_values(array_unique(array_map('intval', [
            ...($service->owner_group_id ? [$service->owner_group_id] : []),
            ...($service->group_ids ?? []),
        ])));

        return $ids === [] ? null : $ids;
    }
}
