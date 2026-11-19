<?php

namespace Modules\Schichtplan\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** One time block of a plan */
/**
 * @property int $id
 * @property int $plan_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 */
class Slot extends Model
{
    protected $table = 'sp_slots';

    protected $fillable = ['plan_id', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** "14:00–15:00" in Berlin time */
    public function label(): string
    {
        return $this->starts_at->copy()->setTimezone('Europe/Berlin')->format('H:i').'–'.$this->ends_at->copy()->setTimezone('Europe/Berlin')->format('H:i');
    }
}
