<?php

namespace Modules\Uebungsdienste\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A swap request: the requester (responsible for $service) asks the target user (responsible for
 * $targetService) to swap. Until the target user answers it is shown to everyone in the plan.
 *
 * @property int $id
 * @property int $service_id
 * @property int $requester_id
 * @property int $target_service_id
 * @property int $target_user_id
 * @property string $status
 * @property-read Service $service
 * @property-read Service $targetService
 * @property-read User $requester
 * @property-read User $targetUser
 */
class Swap extends Model
{
    public const STATUSES = ['pending' => 'angefragt', 'accepted' => 'getauscht', 'declined' => 'abgelehnt', 'withdrawn' => 'zurückgezogen'];

    protected $table = 'ud_swaps';

    protected $fillable = ['service_id', 'requester_id', 'target_service_id', 'target_user_id', 'status', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function targetService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'target_service_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
