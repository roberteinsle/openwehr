<?php

namespace Modules\Uebungsdienste\src\Services;

use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /** $notify: tell the target group by mail about a new Dienst (or about a change of time or place) */
    public function sync(Service $service, ?User $by = null, bool $notify = false): void
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
            // Rückmeldung in the Termin; the Frist is the end of the day $rsvp_days before the Dienst
            'rsvp' => (bool) $service->rsvp,
            'rsvp_deadline' => $service->rsvp && $service->rsvp_days !== null ? $day->copy()->subDays($service->rsvp_days)->endOfDay()->utc() : null,
        ];
        $groups = array_map('intval', $service->group_ids ?? []);
        $functions = array_map('intval', $service->function_ids ?? []);
        $events = app(EventService::class);

        if ($event = $service->event_id ? Event::find($service->event_id) : null) {
            $events->update($event, $data, $groups, false, [], $functions, $by, $notify);
        } else {
            // Who plans the Dienst is not automatically on it – the responsible people are (syncResponsible)
            $event = $events->create([...$data, 'rsvp' => false], $groups, null, functionIds: $functions)->first();
            $event->update(['rsvp' => $data['rsvp'], 'created_by' => $by?->id]);
            if ($notify) {
                $events->announce($event->refresh(), $by);
            }
        }
        $service->forceFill(['event_id' => $event->id, 'calendar_synced_at' => now()])->saveQuietly();
        $this->syncResponsible($service);
    }

    /** @param  Collection<int, Service>  $services  a new series: one mail to the target group, with the number of Dienste */
    public function announceSeries($services, ?User $by): void
    {
        $first = $services->first()?->refresh();
        if (self::available() && $first?->event_id && ($event = Event::find($first->event_id))) {
            app(EventService::class)->announce($event, $by, $services->count());
        }
    }

    /**
     * The responsible people of a Dienst take part: in its Termin they are zugesagt unless they already
     * answered (they can change it like anyone).
     */
    public function syncResponsible(Service $service): void
    {
        if (! self::available() || ! $service->event_id || ! $service->rsvp) {
            return;
        }
        $event = Event::find($service->event_id);
        if (! $event) {
            return;
        }
        $answered = $event->responses()->whereNotNull('status')->pluck('user_id')->all();
        foreach ($service->responsible()->get() as $user) {
            if (! in_array($user->id, $answered, true)) {
                app(EventService::class)->respondFor($event, $user, 'yes', $user);
            }
        }
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
