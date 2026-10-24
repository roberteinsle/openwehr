<?php

namespace Modules\Uebungsdienste\src\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A vehicle an Übungsdienst needs and the booking made for it (booking_id null = not booked, see note)
 *
 * @property int $vehicle_id
 * @property ?int $booking_id
 * @property ?string $note
 */
class ServiceVehicle extends Model
{
    public $timestamps = false;

    protected $table = 'ud_service_vehicles';

    protected $fillable = ['service_id', 'vehicle_id', 'booking_id', 'note'];
}
