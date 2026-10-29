<?php

namespace Modules\Mitteilungen\src\Models;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A notice on the start page; important ones are highlighted and cannot be dismissed. Meant for Abteilungen
 * and/or Funktionen (empty = everyone), with attachments and, on request, a Lesebestätigung.
 *
 * @property int $id
 * @property string $title
 * @property string|null $body
 * @property bool $important
 * @property bool $needs_ack
 * @property Carbon|null $visible_from
 * @property Carbon|null $visible_until
 * @property int|null $created_by
 */
class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'important', 'needs_ack', 'visible_from', 'visible_until', 'created_by'];

    protected function casts(): array
    {
        return ['important' => 'boolean', 'needs_ack' => 'boolean', 'visible_from' => 'datetime', 'visible_until' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Audience; empty means everyone */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    /** Audience by Funktion (a Funktion includes its sub-functions) */
    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(MemberFunction::class, 'announcement_function');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AnnouncementFile::class)->select(['id', 'announcement_id', 'filename', 'mime', 'size']);
    }

    /** Who opened it, and who confirmed it (Lesebestätigung) */
    public function reads(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_reads')->withPivot(['read_at', 'acknowledged_at']);
    }

    public function dismissedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_dismissals');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('visible_from')->orWhere('visible_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('visible_until')->orWhere('visible_until', '>=', now()));
    }

    /** Meant for this Kamerad: no audience set, member of one of the Abteilungen, or holding one of the Funktionen */
    public function scopeFor(Builder $query, User $user): Builder
    {
        $functions = $user->functions()->pluck('member_functions.id');
        // A Funktion of the audience also covers its sub-functions, so look one level up
        $withParents = $functions->merge(MemberFunction::whereIn('id', $functions)->whereNotNull('parent_id')->pluck('parent_id'))->unique();

        return $query->where(fn ($q) => $q->where(fn ($all) => $all->doesntHave('groups')->doesntHave('functions'))
            ->orWhereHas('groups', fn ($g) => $g->whereIn('groups.id', $user->groups()->pluck('groups.id')))
            ->orWhereHas('functions', fn ($f) => $f->whereIn('member_functions.id', $withParents)));
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->current()->for($user)
            ->where(fn ($q) => $q->where('important', true)
                ->orWhereDoesntHave('dismissedBy', fn ($d) => $d->where('users.id', $user->id)));
    }
}
