<?php

namespace Modules\Uebungsdienste\src\Models;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Place;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One Übungsdienst: date, start and end (Berlin time), place, Kleidung, target group, topic and who
 * is responsible. Without the topic it is also a Termin in the Kalender of the target group.
 *
 * @property int $id
 * @property Carbon $date
 * @property ?string $starts_at
 * @property ?string $ends_at
 * @property ?string $location
 * @property ?string $topic
 * @property ?string $organizer
 * @property ?int $owner_group_id
 * @property ?array $group_ids
 * @property ?array $function_ids
 * @property ?array $dress_code_ids
 * @property-read ?Group $ownerGroup
 */
class Service extends Model
{
    protected $table = 'ud_services';

    /** Length when no end is given */
    public const DEFAULT_MINUTES = 120;

    protected $fillable = ['date', 'starts_at', 'ends_at', 'location', 'place_id', 'dress_code_ids', 'group_ids', 'function_ids', 'topic', 'event_id', 'calendar_synced_at',
        'owner_group_id', 'organizer'];

    protected function casts(): array
    {
        return ['date' => 'date', 'calendar_synced_at' => 'datetime', 'dress_code_ids' => 'array', 'group_ids' => 'array', 'function_ids' => 'array'];
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** The Abteilung the Dienst is planned for; null = the whole Wehr */
    /** @return BelongsTo<Group, $this> */
    public function ownerGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'owner_group_id');
    }

    /** Vehicles the Dienst needs, with their booking */
    /** @return HasMany<ServiceVehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(ServiceVehicle::class, 'service_id');
    }

    /** Swap requests waiting for an answer that involve this Dienst */
    public function pendingSwaps()
    {
        return Swap::with(['requester', 'targetUser', 'service', 'targetService'])->where('status', 'pending')
            ->where(fn ($q) => $q->where('service_id', $this->id)->orWhere('target_service_id', $this->id));
    }

    /** Start and end in Berlin time; null without a start time */
    public function period(): ?array
    {
        if (! $this->starts_at) {
            return null;
        }
        $start = Carbon::createFromFormat('Y-m-d H:i', $this->date->toDateString().' '.$this->starts_at, 'Europe/Berlin');
        $end = $this->ends_at ? Carbon::createFromFormat('Y-m-d H:i', $this->date->toDateString().' '.$this->ends_at, 'Europe/Berlin') : $start->copy()->addMinutes(self::DEFAULT_MINUTES);
        if ($end->lte($start)) {
            $end->addDay();   // until after midnight
        }

        return [$start, $end];
    }

    /** "19:00–21:00 Uhr" */
    public function timeLabel(): ?string
    {
        return $this->starts_at ? $this->starts_at.($this->ends_at ? '–'.$this->ends_at : '').' Uhr' : null;
    }

    /** "Jugendfeuerwehr, Maschinist" or null for everyone */
    public function audienceLabel(): ?string
    {
        $names = [
            ...Group::whereIn('id', $this->group_ids ?? [])->orderBy('name')->pluck('name')->all(),
            ...MemberFunction::whereIn('id', $this->function_ids ?? [])->orderBy('name')->pluck('name')->all(),
        ];

        return $names === [] ? null : implode(', ', $names);
    }

    /** @return BelongsToMany<User, $this> */
    public function responsible(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ud_service_user', 'service_id', 'user_id')->orderBy('name');
    }

    /** "Di, 14.10.2026" */
    public function dateLabel(): string
    {
        return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$this->date->dayOfWeek].', '.$this->date->format('d.m.Y');
    }
}
