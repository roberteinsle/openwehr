<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Monitor;

use Illuminate\Support\Str;
use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Support\VehicleBoard;

/**
 * Read-only board for the screen at the station. Refreshes itself every minute
 * (wire:poll) and deliberately offers no way to change anything.
 */
class StationBoard extends Component
{
    public function render()
    {
        $board = VehicleBoard::data();

        return view('fahrzeugbuchung::livewire.monitor.station-board', [
            'vehicles' => $board['vehicles'],
            'upcoming' => $board['bookings']->take(14),
            'counts' => $board['counts'],
            'updatedAt' => now()->setTimezone('Europe/Berlin'),
        ]);
    }

    /** "Hanna Hydrant" -> "Hanna H." – the screen may be visible to visitors */
    public static function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name));
        $first = array_shift($parts) ?? '';
        $last = array_pop($parts);

        return $last ? $first.' '.Str::upper(Str::substr($last, 0, 1)).'.' : $first;
    }
}
