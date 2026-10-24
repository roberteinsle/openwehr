<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lets modules book vehicles without knowing the Fahrzeugbuchung (e.g. the Übungsdienstplaner books
 * the vehicles a Dienst needs). The Fahrzeugbuchung registers itself as the booker; while it is off
 * or not installed, available() is false and nothing is booked.
 *
 * The booker: vehicles() → [id => name] of bookable vehicles; book(vehicleId, from, to, purpose, by)
 * → booking id, or a ValidationException with the reason (already booked, Werkstatt …); cancel(bookingId).
 */
class VehicleBookingRegistry
{
    /** @var ?array{module: string, vehicles: callable(): Collection, book: callable(int, CarbonInterface, CarbonInterface, string, User): int, cancel: callable(int): void} */
    private ?array $booker = null;

    public function register(string $module, callable $vehicles, callable $book, callable $cancel): void
    {
        $this->booker = compact('module', 'vehicles', 'book', 'cancel');
    }

    public function available(): bool
    {
        return $this->booker !== null && app(ModuleRegistry::class)->isActive($this->booker['module']);
    }

    /** @return Collection<int, string> */
    public function vehicles(): Collection
    {
        return $this->available() ? collect(($this->booker['vehicles'])()) : collect();
    }

    public function book(int $vehicleId, CarbonInterface $from, CarbonInterface $to, string $purpose, User $by): int
    {
        return ($this->booker['book'])($vehicleId, $from, $to, $purpose, $by);
    }

    /** Cancels a booking made through book(); a booking that is gone or already over is left alone */
    public function cancel(int $bookingId): void
    {
        if ($this->available()) {
            ($this->booker['cancel'])($bookingId);
        }
    }
}
