<?php

namespace Modules\Kalender\src\Support;

use App\Support\MonitorColumns;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Modules\Kalender\src\Models\Event;

/**
 * Kalender contents of the Wachen-Monitor: the next Termine (all Abteilungen, like the Termin-Monitor)
 * and the same side by side per Abteilung. Personal, cancelled and external Termine stay off, see
 * Roster::upcoming.
 */
final class MonitorPanel
{
    private const DAYS = 60;

    /** Rows in the plain list per field size */
    private const ROWS = ['full' => 12, 'half' => 8, 'quarter' => 4];

    /** @param  array{size: string, group_ids: list<int>}  $options */
    public static function upcoming(array $options): View
    {
        return view('kalender::monitor.upcoming', [
            'events' => Roster::upcoming(self::DAYS)->take(self::ROWS[$options['size']] ?? 4),
            'size' => $options['size'],
            ...self::days(),
        ]);
    }

    /** @param  array{size: string, group_ids: list<int>}  $options */
    public static function groups(array $options): View
    {
        return view('kalender::monitor.groups', [
            'columns' => MonitorColumns::build(Roster::upcoming(self::DAYS), fn (Event $e) => self::groupIds($e), $options),
            'size' => $options['size'],
            ...self::days(),
        ]);
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
    private static function days(): array
    {
        return ['when' => fn (Event $e) => self::when($e)];
    }
}
