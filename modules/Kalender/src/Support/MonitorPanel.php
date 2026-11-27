<?php

namespace Modules\Kalender\src\Support;

use App\Support\MonitorColumns;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Modules\Kalender\src\Models\Event;

/**
 * Kalender contents of the Wachen-Monitor: the coming days as rows, the next Termine (all Abteilungen,
 * like the Termin-Monitor) and the same side by side per Abteilung. Personal, cancelled and external Termine stay off, see
 * Roster::upcoming. Termine kept by a module whose own content is on the screen too (covered, e.g. the
 * Übungsdienste) are left out, so nothing shows twice.
 */
final class MonitorPanel
{
    private const DAYS = 60;

    /** Rows in the plain list per field size */
    private const ROWS = ['full' => 12, 'half' => 8, 'third' => 10, 'quarter' => 4];

    /** Days in the day list per field size (a quarter only has room for one week) */
    private const DAY_COUNT = ['full' => 14, 'half' => 14, 'third' => 14, 'quarter' => 7];

    /** Termine side by side in one day row; more are counted as "+n" */
    private const PER_DAY = ['full' => 4, 'half' => 2, 'third' => 2, 'quarter' => 2];

    /** "Nächste Termine" under the day list */
    private const NEXT = ['full' => 5, 'half' => 3, 'third' => 3, 'quarter' => 0];

    /** @param  array{size: string, group_ids: list<int>, covered?: list<string>}  $options */
    public static function upcoming(array $options): View
    {
        return view('kalender::monitor.upcoming', [
            'events' => self::events($options)->take(self::ROWS[$options['size']] ?? 4),
            'size' => $options['size'],
            ...self::helpers(),
        ]);
    }

    /**
     * "Kalender": one row per day from today on (two weeks, a quarter field one), each with the
     * titles of its Termine, weekends shaded; below it the next Termine with their time.
     *
     * @param  array{size: string, group_ids: list<int>, covered?: list<string>}  $options
     */
    public static function days(array $options): View
    {
        $size = $options['size'];
        $count = self::DAY_COUNT[$size] ?? 7;
        $today = now('Europe/Berlin')->startOfDay();
        $events = self::events($options);

        $days = collect(range(0, $count - 1))->map(function (int $i) use ($today, $events) {
            $day = $today->copy()->addDays($i);
            $next = $day->copy()->addDay();

            return [
                'date' => $day,
                'weekend' => $day->isWeekend(),
                'today' => $i === 0,
                // A Termin over several days shows on each of them
                'events' => $events->filter(fn (Event $e) => Carbon::parse($e->getAttribute('starts_at'))->lt($next)
                    && Carbon::parse($e->getAttribute('ends_at'))->gt($day))->values(),
            ];
        });

        return view('kalender::monitor.days', [
            'days' => $days,
            'perDay' => self::PER_DAY[$size] ?? 2,
            'next' => $events->take(self::NEXT[$size] ?? 0),
            'size' => $size,
            ...self::helpers(),
        ]);
    }

    /** @param  array{size: string, group_ids: list<int>, covered?: list<string>}  $options */
    public static function groups(array $options): View
    {
        return view('kalender::monitor.groups', [
            'columns' => MonitorColumns::build(self::events($options), fn (Event $e) => self::groupIds($e), $options),
            'size' => $options['size'],
            ...self::helpers(),
        ]);
    }

    /** @param  array{covered?: list<string>}  $options */
    private static function events(array $options)
    {
        $covered = $options['covered'] ?? [];

        return Roster::upcoming(self::DAYS)->reject(fn (Event $e) => $e->managed_by !== null && in_array($e->managed_by, $covered, true))->values();
    }

    /** @return ?list<int> the Abteilungen of a Termin, null = the whole Wehr (also one only for Funktionen) */
    public static function groupIds(Event $event): ?array
    {
        $ids = $event->groups->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return $ids === [] ? null : $ids;
    }

    /** "Heute", "Morgen" or "Di, 14.10." and "19:00–21:00" or "ganztägig" */
    public static function when(Event $event): string
    {
        $start = Carbon::parse($event->getAttribute('starts_at'))->setTimezone('Europe/Berlin');
        $end = Carbon::parse($event->getAttribute('ends_at'))->setTimezone('Europe/Berlin');
        $today = now('Europe/Berlin');
        $day = match ($start->toDateString()) {
            $today->toDateString() => 'Heute',
            $today->copy()->addDay()->toDateString() => 'Morgen',
            default => ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$start->dayOfWeek].', '.$start->format('d.m.'),
        };

        return $day.', '.($event->all_day ? 'ganztägig' : $start->format('H:i').'–'.$end->format('H:i'));
    }

    /** @return array{when: callable} */
    private static function helpers(): array
    {
        return ['when' => fn (Event $e) => self::when($e)];
    }
}
