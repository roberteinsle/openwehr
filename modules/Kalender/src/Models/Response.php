<?php

namespace Modules\Kalender\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int $user_id
 * @property ?string $status the answer that counts: the parents' when there is one
 * @property ?string $comment
 * @property ?string $own_status the Kamerad's (child's) own answer
 * @property ?string $guardian_status
 * @property ?string $guardian_comment
 * @property ?int $guardian_id
 * @property ?string $guardian_name
 * @property ?Carbon $guardian_at
 * @property ?bool $attended
 * @property ?int $set_by
 */
class Response extends Model
{
    public const STATUSES = ['yes' => 'Zugesagt', 'maybe' => 'Vielleicht', 'no' => 'Abgesagt'];

    protected $table = 'kal_responses';

    protected $fillable = ['event_id', 'user_id', 'status', 'comment', 'attended', 'set_by',
        'own_status', 'guardian_status', 'guardian_comment', 'guardian_id', 'guardian_name', 'guardian_at'];

    protected function casts(): array
    {
        return ['attended' => 'boolean', 'guardian_at' => 'datetime'];
    }

    /** Child and Erziehungsberechtigte answered differently (e.g. the child said yes, the parents no) */
    public function mismatch(): bool
    {
        return $this->own_status !== null && $this->guardian_status !== null && $this->own_status !== $this->guardian_status;
    }

    public static function labelOf(?string $status): string
    {
        return self::STATUSES[$status] ?? 'Keine Rückmeldung';
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::STATUSES[$this->status] ?? 'Keine Rückmeldung';
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
