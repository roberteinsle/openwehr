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
use Illuminate\Support\Carbon;
use Modules\Kalender\src\Support\Audience;

/**
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 */
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
        'place_id', 'dress_code_ids', 'managed_by', 'organizer', 'attendance_closed_at', 'attendance_closed_by', 'attendance_changed_at', 'attendance_reminded_at',
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
            'attendance_closed_at' => 'datetime',
            'attendance_changed_at' => 'datetime',
            'attendance_reminded_at' => 'datetime',
            'all_day' => 'boolean',
            'rsvp' => 'boolean',
            'is_public' => 'boolean',
            'min_participants' => 'integer',
        ];
    }

    /**
     * Who sent the Anwesenheit; null when it went out automatically
     *
     * @return BelongsTo<User, $this>
     */
    public function attendanceClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_closed_by');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'kal_event_group', 'event_id', 'group_id');
    }

    /** @return BelongsTo<Place, $this> */
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

    /** "Jugendfeuerwehr" or "Einsatzabteilung, Jugendfeuerwehr" (Abteilungen, then Funktionen); null when it is for everyone */
    public function audienceLabel(): ?string
    {
        $names = [...$this->groups->pluck('name')->all(), ...$this->functions->pluck('name')->all()];

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * Whether the viewer needs to see for which Abteilung a Termin is: in more than one Abteilung (e.g.
     * Einsatzabteilung and Jugendfeuerwehr, where both have an "Übungsdienst") or seeing every Termin.
     */
    public static function showAudienceTo(?User $user): bool
    {
        return $user !== null && ($user->canManageKalender() || $user->groups()->count() > 1);
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
    /**
     * Zusagen and Absagen can be changed until the Termin begins; the Frist (rsvp_deadline) is the time by
     * which an answer is asked for – later ones still count and are marked "nach der Frist".
     */
    public function acceptsResponses(): bool
    {
        return $this->rsvp && ! $this->isCancelled() && ! $this->starts_at->isPast();
    }

    public function pastDeadline(): bool
    {
        return $this->rsvp_deadline !== null && now()->gt($this->getAttribute('rsvp_deadline'));
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
