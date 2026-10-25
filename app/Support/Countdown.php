<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How long until a Dienst or Termin, for the start page: whole days (calendar days in Berlin) while
 * it is on another day, hours on the day itself, minutes in the last 59 minutes. The countdown
 * component recalculates the same in the browser.
 */
class Countdown
{
    public static function label(CarbonInterface $start, bool $allDay = false, ?CarbonInterface $now = null): string
    {
        $now = Carbon::instance($now ?? now())->setTimezone('Europe/Berlin');
        $start = Carbon::instance($start)->setTimezone('Europe/Berlin');
        $days = (int) $now->copy()->startOfDay()->diffInDays($start->copy()->startOfDay(), false);

        if ($days >= 1) {
            return 'noch '.$days.' '.($days === 1 ? 'Tag' : 'Tage');
        }
        if ($allDay || $start->lte($now)) {
            return $start->lte($now) && ! $allDay ? 'läuft' : 'heute';
        }
        $minutes = (int) ceil($now->diffInSeconds($start) / 60);

        return $minutes >= 60 ? 'noch '.intdiv($minutes, 60).' Std.' : 'noch '.$minutes.' Min.';
    }
}
