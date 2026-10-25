<?php

namespace Modules\Kalender\src\Models;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Place;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Kalender\src\Support\Audience;

class Event extends Model
{
    /** @return array<string, string> key => label of the Terminarten to choose from (an inactive one only when kept) */
    public static function types(?string $keep = null): array
    {
        return EventType::options($keep);
    }

    protected $table = 'kal_events';

    protected $attributes = ['type' => 'uebung', 'all_day' => false, 'rsvp' => false, 'is_public' => false];

    protected $fillable = [
        'title', 'type', 'starts_at', 'ends_at', 'all_day', 'location', 'partners', 'description', 'rsvp', 'rsvp_deadline',
        'min_participants', 'is_public', 'reminder_sent_at', 'series_id', 'source_id', 'external_uid', 'cancelled_at', 'created_by',
        'place_id', 'dress_code_ids', 'managed_by', 'organizer',
    ];

    /** Modules that keep events in sync, and where to change them */
    public const MANAGED_BY = ['uebungsdienste' => 'Übungsdienstplaner', 'schichtplan' => 'Schichtplan', 'fuehrerscheine' => 'Führerscheine'];

    protected function casts(): array
    {
        return [
            'dress_code_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'rsvp_deadline' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'all_day' => 'boolean',
            'rsvp' => 'boolean',
            'is_public' => 'boolean',
            'min_participants' => 'integer',
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'kal_event_group', 'event_id', 'group_id');
    }

    /** Funktionen of the target group (each includes its sub-functions) */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(MemberFunction::class, 'kal_event_function', 'event_id', 'function_id');
    }

    /** Kameraden invited individually */
    public function invitees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'kal_event_user', 'event_id', 'user_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class, 'event_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Terminverwalter see everything; everyone else the events for all (no target group set), for
     * their Abteilungen or Funktionen, and those they are invited to personally.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->canManageKalender() ? $query : Audience::scope($query, $user);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->whereNull('cancelled_at');
    }

    /** Overlaps [from, until) */
    public function scopeBetween(Builder $query, $from, $until): Builder
    {
        return $query->where('starts_at', '<', $until)->where('ends_at', '>', $from);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')->where('ends_at', '>', now())->orderBy('starts_at');
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }

    public function typeLabel(): string
    {
        return EventType::labelOf($this->type);
    }

    /** Inline style of the type badge, in the colour the admin chose for the Terminart */
    public function colorStyle(): string
    {
        return EventType::styleOf($this->type);
    }

    public function isExternal(): bool
    {
        return $this->source_id !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Rückmeldung still possible */
    public function acceptsResponses(): bool
    {
        return $this->rsvp && ! $this->isCancelled() && ! $this->ends_at->isPast()
            && ($this->rsvp_deadline === null || $this->rsvp_deadline->isFuture());
    }

    /** "Di, 14.06.2027 · 19:00–21:30 Uhr" or "Sa, 12.06.2027 (ganztägig)" in Berlin time */
    public function timeLabel(): string
    {
        $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $start = $this->starts_at->copy()->setTimezone('Europe/Berlin');
        $end = $this->ends_at->copy()->setTimezone('Europe/Berlin');

        if ($this->all_day) {
            $last = $end->copy()->subSecond();

            return $days[$start->dayOfWeek].', '.$start->format('d.m.Y').($last->isSameDay($start) ? '' : ' – '.$days[$last->dayOfWeek].', '.$last->format('d.m.Y')).' (ganztägig)';
        }

        return $days[$start->dayOfWeek].', '.$start->format('d.m.Y').' · '.$start->format('H:i').'–'
            .($end->isSameDay($start) ? $end->format('H:i') : $days[$end->dayOfWeek].', '.$end->format('d.m.Y H:i')).' Uhr';
    }
}
