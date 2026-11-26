<?php

namespace Modules\Kalender\src\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Birthdays of the active Kameraden in the Kalender (month, week and list), from the optional birth
 * date of the member data. Day and name only – no year, no age. Switched on and off by the admins
 * on the Terminarten page (setting kalender.birthdays, on by default). Not a Termin: no Rückmeldung,
 * not in the Abo, on monitors or the Dienstplan.
 */
final class Birthdays
{
    public const SETTING = 'kalender.birthdays';

    public static function enabled(): bool
    {
        return Setting::get(self::SETTING, '1') !== '0';
    }

    public static function setEnabled(bool $on): void
    {
        Setting::set(self::SETTING, $on ? '1' : '0');
    }

    /**
     * The birthdays from $from up to $until (exclusive), Berlin calendar days. A 29 February is on
     * 28 February in other years.
     *
     * @return Collection<string, non-empty-list<string>> Y-m-d => names
     */
    public static function between(Carbon $from, Carbon $until): Collection
    {
        if (! self::enabled()) {
            return collect();
        }

        $people = User::active()->whereNotNull('birth_date')->orderBy('name')->get(['id', 'name', 'birth_date']);
        $days = [];
        for ($year = $from->year; $year <= $until->year; $year++) {
            foreach ($people as $user) {
                $birth = Carbon::parse($user->getAttribute('birth_date'));
                $day = $birth->month === 2 && $birth->day === 29 && ! Carbon::create($year)->isLeapYear() ? 28 : $birth->day;
                $date = Carbon::create($year, $birth->month, $day, 0, 0, 0, 'Europe/Berlin');
                if ($date->gte($from->copy()->startOfDay()) && $date->lt($until)) {
                    $days[$date->toDateString()][] = $user->name;
                }
            }
        }
        ksort($days);

        return collect($days);
    }
}
