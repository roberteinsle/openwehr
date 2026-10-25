<?php

namespace Modules\Kalender\src\Models;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Kalender\src\Support\Audience;

/**
 * A Terminabfrage: date proposals the target group answers with yes/maybe/no; the Terminverwalter
 * fixes one, which becomes an event of the Kalender.
 */
class Poll extends Model
{
    protected $table = 'kal_polls';

    protected $attributes = ['type' => 'veranstaltung'];

    protected $fillable = ['title', 'type', 'location', 'description', 'deadline', 'closed_at', 'event_id', 'reminder_sent_at', 'created_by'];

    protected function casts(): array
    {
        return ['deadline' => 'datetime', 'closed_at' => 'datetime', 'reminder_sent_at' => 'datetime'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class, 'poll_id')->orderBy('starts_at');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'kal_poll_group', 'poll_id', 'group_id');
    }

    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(MemberFunction::class, 'kal_poll_function', 'poll_id', 'function_id');
    }

    public function invitees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'kal_poll_user', 'poll_id', 'user_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->canManageKalender() ? $query : Audience::scope($query, $user);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null && ($this->deadline === null || $this->deadline->isFuture());
    }

    public function isDecided(): bool
    {
        return $this->event_id !== null;
    }

    public function typeLabel(): string
    {
        return EventType::labelOf($this->type);
    }
}
