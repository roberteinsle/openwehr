<?php

namespace Modules\Schichtplan\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    protected $table = 'sp_assignments';

    public const CONFIRMED = 'confirmed';

    /** Self sign-up with approval: the place is held until the planer or the Stationsverantwortung decides */
    public const REQUESTED = 'requested';

    protected $fillable = ['plan_id', 'slot_id', 'station_id', 'user_id', 'locked', 'status', 'signed_up_by', 'reminded_at'];

    protected $attributes = ['status' => self::CONFIRMED];

    protected function casts(): array
    {
        return ['locked' => 'boolean', 'reminded_at' => 'datetime'];
    }

    public function isRequested(): bool
    {
        return $this->status === self::REQUESTED;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** @return BelongsTo<Slot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class, 'slot_id');
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
