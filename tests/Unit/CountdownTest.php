<?php

use App\Support\Countdown;
use Illuminate\Support\Carbon;

test('the countdown shows days, on the day hours, in the last 59 minutes minutes', function () {
    $now = Carbon::parse('2027-03-10 18:00', 'Europe/Berlin');
    $at = fn (string $time) => Carbon::parse($time, 'Europe/Berlin');

    expect(Countdown::label($at('2027-03-13 19:00'), false, $now))->toBe('noch 3 Tage')
        ->and(Countdown::label($at('2027-03-11 00:30'), false, $now))->toBe('noch 1 Tag')   // another day, even if only hours away
        ->and(Countdown::label($at('2027-03-10 21:30'), false, $now))->toBe('noch 3 Std.')
        ->and(Countdown::label($at('2027-03-10 19:00'), false, $now))->toBe('noch 1 Std.')
        ->and(Countdown::label($at('2027-03-10 18:59'), false, $now))->toBe('noch 59 Min.')
        ->and(Countdown::label($at('2027-03-10 18:00'), false, $now))->toBe('läuft')
        ->and(Countdown::label($at('2027-03-10 00:00'), true, $now))->toBe('heute')
        ->and(Countdown::label($at('2027-03-12 00:00'), true, $now))->toBe('noch 2 Tage');
});
