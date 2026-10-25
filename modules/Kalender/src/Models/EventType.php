<?php

namespace Modules\Kalender\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Once;

/**
 * A Terminart (Übung, Dienst, Veranstaltung …): admins name, colour, sort and add them. Events,
 * Terminabfragen and external calendars store the key. Keys other modules write (PROTECTED) can be
 * renamed and recoloured but not deleted.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string $color
 * @property int $sort_order
 * @property bool $is_active
 */
class EventType extends Model
{
    /** Übungsdienstplaner (uebung), Schichtplan (veranstaltung), Fahrzeuge (wartung), Führerscheine (sonstiges) */
    public const PROTECTED = ['uebung', 'veranstaltung', 'wartung', 'sonstiges'];

    public const FALLBACK_COLOR = '#4B5563';

    protected $table = 'kal_types';

    protected $fillable = ['key', 'label', 'color', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Once::flush());
        static::deleted(fn () => Once::flush());
    }

    /** @return Collection<string, EventType> all types by key, in order (read once per request) */
    public static function ordered(): Collection
    {
        return once(fn () => self::query()->orderBy('sort_order')->orderBy('label')->get()->keyBy('key'));
    }

    /** @return array<string, string> key => label of the types to choose from (inactive ones only where already used) */
    public static function options(?string $keep = null): array
    {
        return self::ordered()->filter(fn (EventType $t) => $t->is_active || $t->key === $keep)->map(fn (EventType $t) => $t->label)->all();
    }

    /** @return array<string, string> key => label of every type, also switched-off ones */
    public static function labels(): array
    {
        return self::ordered()->map(fn (EventType $t) => $t->label)->all();
    }

    public static function labelOf(?string $key): string
    {
        return self::ordered()->get((string) $key)->label ?? (string) $key;
    }

    public static function colorOf(?string $key): string
    {
        return self::ordered()->get((string) $key)->color ?? self::FALLBACK_COLOR;
    }

    /** Inline style of a badge in the type's colour: tinted background, coloured text and border */
    public static function styleOf(?string $key): string
    {
        $c = self::colorOf($key);

        return 'background-color: '.$c.'1A; color: '.$c.'; border-color: '.$c.'55;';
    }
}
