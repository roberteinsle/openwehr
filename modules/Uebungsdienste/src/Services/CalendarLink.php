<?php

namespace Modules\Uebungsdienste\src\Services;

use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Support\Carbon;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;
use Modules\Uebungsdienste\src\Models\Service;
use Throwable;

/**
 * While the Kalender is on, every Übungsdienst is a Termin "Übungsdienst" (type Übung) in the
 * Kalender of its target group: time, place, Kleidung and target group follow the plan. The topic
 * and the responsible people stay in the plan for the Vorstand – the Termin never shows them.
 */
class CalendarLink
{
    public const TITLE = 'Übungsdienst';

    public static function available(): bool
    {
        return app(ModuleRegistry::class)->isActive('kalender');
    }

    public function sync(Service $service, ?User $by = null): void
    {
        if (! self::available()) {
            return;
        }
        $service->loadMissing('place');
        $day = Carbon::createFromFormat('!Y-m-d', $service->date->toDateString(), 'Europe/Berlin');
        [$start, $end] = $service->period() ?? [$day, $day->copy()->addDay()];

        $data = [
            'title' => self::TITLE, 'type' => 'uebung', 'starts_at' => $start, 'ends_at' => $end, 'all_day' => ! $service->starts_at,
            'location' => $service->place?->name ?? $service->location, 'place_id' => $service->place_id,
            'dress_code_ids' => $service->dress_code_ids ?: null, 'description' => null, 'managed_by' => 'uebungsdienste', 'organizer' => $service->organizer,
        ];
        $groups = array_map('intval', $service->group_ids ?? []);
        $functions = array_map('intval', $service->function_ids ?? []);
        $events = app(EventService::class);

        if ($event = $service->event_id ? Event::find($service->event_id) : null) {
            $events->update($event, $data, $groups, false, [], $functions, $by);
        } else {
            $event = $events->create($data, $groups, $by, functionIds: $functions)->first();
        }
        $service->forceFill(['event_id' => $event->id, 'calendar_synced_at' => now()])->saveQuietly();
    }

    /** The Dienst is gone: its Termin too */
    public function remove(Service $service): void
    {
        if ($service->event_id) {
            Event::whereKey($service->event_id)->delete();
        }
    }

    /**
     * Hourly: Dienste of the last twelve months and the coming ones that are not in the Kalender
     * yet (e.g. the Kalender was switched on later). One deleted in the Kalender stays deleted.
     */
    public function syncMissing(): int
    {
        if (! self::available()) {
            return 0;
        }
        $count = 0;
        Service::whereNull('event_id')->whereNull('calendar_synced_at')->whereDate('date', '>=', ServicePlan::today()->subYear())
            ->orderBy('date')->each(function (Service $service) use (&$count) {
                try {
                    $this->sync($service);
                    $count++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }
}
