<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An Abteilung (Einsatzabteilung, Jugendfeuerwehr, Musikzug …) with its colours. A Jugendabteilung
 * (is_youth) can keep modules from its members (excluded_modules), let them join the Übungsdienste of
 * another Abteilung from an age on (guest_group_id, guest_from_age → group_guests) and name the
 * Abteilung they move to (transfer_group_id, transfer_age) – see App\Services\AgeTransitions.
 *
 * @property int $id
 * @property string $name
 * @property ?string $color
 * @property ?string $color_secondary
 * @property bool $is_youth
 * @property ?list<string> $excluded_modules
 * @property ?int $guest_group_id
 * @property ?int $guest_from_age
 * @property ?int $transfer_group_id
 * @property ?int $transfer_age
 */
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /** The colour of Abteilungen without one */
    public const DEFAULT_COLOR = '#6B7280';

    protected $fillable = [
        'name',
        'sort_order',
        'color',
        'color_secondary',
        'is_youth',
        'excluded_modules',
        'guest_group_id',
        'guest_from_age',
        'transfer_group_id',
        'transfer_age',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_youth' => 'boolean',
            'excluded_modules' => 'array',
            'guest_from_age' => 'integer',
            'transfer_age' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A new Abteilung gets the usual colours by its name
        static::creating(function (Group $group) {
            if ($group->color === null) {
                [$group->color, $group->color_secondary] = self::suggestedColors($group->name);
            }
            if ($group->getAttribute('is_youth') === null) {
                $group->is_youth = str_contains(mb_strtolower($group->name), 'jugend');
            }
        });
    }

    /** @return array{0: ?string, 1: ?string} Aktive/Einsatzabteilung red with dark blue, Jugendfeuerwehr orange and blue */
    public static function suggestedColors(string $name): array
    {
        $name = mb_strtolower($name);

        return match (true) {
            str_contains($name, 'jugend') => ['#F28C28', '#1D4ED8'],
            str_contains($name, 'aktiv'), str_contains($name, 'einsatz') => ['#ED2F12', '#1B2645'],
            default => [null, null],
        };
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** @return BelongsToMany<User, $this> Jugendliche who join this Abteilung's Übungsdienste */
    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_guests')->withTimestamps();
    }

    /** @return BelongsTo<Group, $this> */
    public function guestGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'guest_group_id');
    }

    /** @return BelongsTo<Group, $this> */
    public function transferGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'transfer_group_id');
    }

    public function colorOrDefault(): string
    {
        return $this->color ?: self::DEFAULT_COLOR;
    }

    /** Inline style of a badge: the colour as background, the second one as border */
    public function badgeStyle(): string
    {
        $bg = $this->colorOrDefault();

        return 'background-color: '.$bg.'; color: '.self::textOn($bg).'; border: 2px solid '.($this->color_secondary ?: $bg).';';
    }

    /** White or dark text, whichever reads better on the colour */
    public static function textOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        [$r, $g, $b] = array_map('hexdec', str_split(strlen($hex) === 3 ? preg_replace('/(.)/', '$1$1', $hex) : $hex, 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111827' : '#FFFFFF';
    }
}
