<?php

namespace Modules\Fahrzeugbuchung\src\Support;

use App\Services\AlarmService;
use Illuminate\Support\Collection;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/**
 * State of every vehicle for the screens at the station (the Fahrzeug-Monitor and the field
 * "Fahrzeugbelegung" on the Wachen-Monitor): free, booked soon or out, with the current or next booking.
 * While a DIVERA alarm runs, the alarmed vehicles are "Im Einsatz" (state alarm, counted as busy) –
 * from the core AlarmService, without a booking.
 */
final class VehicleBoard
{
    /** A next booking this close counts as "bald gebucht" */
    public const SOON_MINUTES = 120;

    public const UPCOMING_DAYS = 7;

    /**
     * @return array{vehicles: Collection<int, array{vehicle: Vehicle, current: ?Booking, next: ?Booking, alarm: ?string, state: string}>,
     *               bookings: Collection<int, Booking>, counts: array{free: int, soon: int, busy: int}}
     */
    public static function data(): array
    {
        $now = now();

        $bookings = Booking::with(['vehicle', 'user'])
            ->visible()
            ->where('ends_at', '>', $now)
            ->where('starts_at', '<', $now->copy()->addDays(self::UPCOMING_DAYS))
            ->orderBy('starts_at')
            ->get();

        $confirmed = $bookings->where('status', Booking::STATUS_CONFIRMED);

        $alarm = AlarmService::busyVehicles();

        $vehicles = Vehicle::active()->get()->map(function (Vehicle $vehicle) use ($confirmed, $now, $alarm) {
            $own = $confirmed->where('vehicle_id', $vehicle->id);
            $current = $own->first(fn (Booking $b) => $b->starts_at <= $now);
            $next = $own->first(fn (Booking $b) => $b->starts_at > $now);

            $inAlarm = $alarm !== null && ($alarm['all'] || AlarmService::vehicleMatches($alarm['names'], $vehicle->name, (string) $vehicle->short_name));

            return [
                'vehicle' => $vehicle,
                'current' => $current,
                'next' => $next,
                'alarm' => $inAlarm ? ($alarm['keyword'] ?? 'Alarm') : null,
                'state' => match (true) {
                    $inAlarm => 'alarm',
                    $current !== null => 'busy',
                    $next && $next->starts_at->diffInMinutes($now, true) <= self::SOON_MINUTES => 'soon',
                    default => 'free',
                },
            ];
        });

        return [
            'vehicles' => $vehicles,
            'bookings' => $bookings,
            'counts' => [
                'free' => $vehicles->where('state', 'free')->count(),
                'soon' => $vehicles->where('state', 'soon')->count(),
                'busy' => $vehicles->whereIn('state', ['busy', 'alarm'])->count(),
            ],
        ];
    }
}
