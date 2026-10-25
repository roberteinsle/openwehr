<?php

namespace Modules\Kalender\src\Livewire;

use Illuminate\Support\Carbon;
use Livewire\Component;
use Modules\Kalender\src\Support\Roster;

/**
 * Read-only Termine board for the screen at the station, like the Wachen-Monitor of the Fahrzeugbuchung:
 * the next Termin with countdown, today and the coming two weeks. Refreshes itself every minute.
 */
class MonitorBoard extends Component
{
    public const DAYS = 14;

    public function render()
    {
        $events = Roster::upcoming(self::DAYS);
        $today = now('Europe/Berlin')->toDateString();

        return view('kalender::livewire.monitor-board', [
            'next' => $events->first(),
            'today' => $events->filter(fn ($e) => Carbon::parse($e->getAttribute('starts_at'))->setTimezone('Europe/Berlin')->toDateString() <= $today)->values(),
            'days' => $events->reject(fn ($e) => Carbon::parse($e->getAttribute('starts_at'))->setTimezone('Europe/Berlin')->toDateString() <= $today)
                ->groupBy(fn ($e) => Carbon::parse($e->getAttribute('starts_at'))->setTimezone('Europe/Berlin')->toDateString()),
            'updatedAt' => now('Europe/Berlin'),
        ]);
    }
}
