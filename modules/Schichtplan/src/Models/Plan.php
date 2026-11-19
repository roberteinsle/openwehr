<?php

namespace Modules\Schichtplan\src\Models;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Plan extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    /** Who fills the places: the planer (and the automatic distribution) or the Kameraden themselves */
    public const SIGNUP_MODES = ['planner' => 'Planer teilt ein', 'self' => 'Mitglieder tragen sich selbst ein'];

    protected $table = 'sp_plans';

    protected $attributes = ['status' => self::DRAFT, 'shift_minutes' => 60, 'max_consecutive' => 2, 'signup' => 'planner', 'needs_approval' => false];

    protected $fillable = [
        'title', 'location', 'starts_at', 'ends_at', 'shift_minutes', 'max_consecutive',
        'group_id', 'status', 'published_at', 'mailed_at', 'created_by',
        'signup', 'signup_until', 'needs_approval', 'max_shifts', 'audience_groups', 'audience_functions', 'event_id', 'event_owned',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'mailed_at' => 'datetime',
            'shift_minutes' => 'integer',
            'max_consecutive' => 'integer',
            'signup_until' => 'datetime',
            'needs_approval' => 'boolean',
            'max_shifts' => 'integer',
            'audience_groups' => 'array',
            'audience_functions' => 'array',
            'event_owned' => 'boolean',
            'notified_snapshot' => 'array',
            'pending_since' => 'datetime',
        ];
    }

    /** @return HasMany<Station, $this> */
    public function stations(): HasMany
    {
        return $this->hasMany(Station::class, 'plan_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Slot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class, 'plan_id')->orderBy('starts_at');
    }

    /** @return HasMany<Participant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'plan_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sp_participants', 'plan_id', 'user_id');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'plan_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** Everyone the plan concerns: participants and Stationsverantwortliche */
    public function concerns(User $user): bool
    {
        return $this->participants()->where('user_id', $user->id)->exists()
            || $this->stations()->where('lead_user_id', $user->id)->exists();
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function isSelfSignup(): bool
    {
        return $this->signup === 'self';
    }

    /** Until when places can be taken and given back: the deadline, else the start of the plan */
    public function signupDeadline(): Carbon
    {
        return $this->signup_until ?? $this->starts_at;
    }

    /** Published, self sign-up and before the deadline */
    public function signupOpen(): bool
    {
        return $this->isPublished() && $this->isSelfSignup() && now()->lt($this->signupDeadline());
    }

    /**
     * Whether the Kamerad belongs to the target group: an Abteilung or a Funktion of the plan
     * (a Funktion includes its sub-functions). Without a target group everyone with access.
     */
    public function inAudience(User $user): bool
    {
        $groups = array_map('intval', $this->audience_groups ?? []);
        $functions = MemberFunction::idsWithChildren($this->audience_functions ?? []);
        if ($groups === [] && $functions === []) {
            return true;
        }

        return ($groups !== [] && $user->groups()->whereIn('groups.id', $groups)->exists())
            || ($functions !== [] && $user->functions()->whereIn('member_functions.id', $functions)->exists());
    }

    /** "Jugendfeuerwehr, Funktion Maschinist" for the page */
    public function audienceLabel(): ?string
    {
        $parts = [
            ...Group::whereIn('id', $this->audience_groups ?? [])->orderBy('name')->pluck('name')->all(),
            ...MemberFunction::whereIn('id', $this->audience_functions ?? [])->orderBy('name')->pluck('name')->all(),
        ];

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** "Sa, 13.06.2027 · 14:00–22:00 Uhr" in Berlin time */
    public function timeLabel(): string
    {
        $start = $this->starts_at->copy()->setTimezone('Europe/Berlin');
        $end = $this->ends_at->copy()->setTimezone('Europe/Berlin');
        $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

        return $days[$start->dayOfWeek].', '.$start->format('d.m.Y').' · '.$start->format('H:i').'–'.$end->format('H:i').' Uhr'
            .($end->isSameDay($start) ? '' : ' (bis '.$end->format('d.m.').')');
    }
}
