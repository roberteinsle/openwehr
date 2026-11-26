<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Setting;
use App\Services\MonitorRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Layout of the Wachen-Monitor: 1, 2 or 4 fields, the content of each (keys of the MonitorRegistry)
 * and the Abteilungen the overviews show. Stored as JSON in the setting monitor.layout; without
 * one the defaults apply, as far as their modules are on.
 */
final class MonitorSettings
{
    public const KEY = 'monitor.layout';

    public const FIELD_COUNTS = [1, 2, 4];

    public const MAX_FIELDS = 4;

    /** Default content per field: the first one whose module is on */
    private const DEFAULTS = [
        ['uebungsdienste.abteilungen', 'kalender.abteilungen'],
        ['fahrzeugbuchung.belegung'],
        ['mitteilungen.aktuell'],
        ['kalender.termine'],
    ];

    /** @return array{fields: int, panels: list<?string>, group_ids: list<int>} panels always has MAX_FIELDS entries */
    public static function get(): array
    {
        $stored = json_decode((string) Setting::get(self::KEY, ''), true);
        $stored = is_array($stored) ? $stored : [];

        $fields = in_array((int) ($stored['fields'] ?? 0), self::FIELD_COUNTS, true) ? (int) $stored['fields'] : self::MAX_FIELDS;
        $panels = isset($stored['panels']) && is_array($stored['panels'])
            ? array_map(fn ($key) => is_string($key) && $key !== '' ? $key : null, array_values($stored['panels']))
            : self::defaultPanels();

        return [
            'fields' => $fields,
            'panels' => array_pad(array_slice($panels, 0, self::MAX_FIELDS), self::MAX_FIELDS, null),
            'group_ids' => array_values(array_map('intval', array_filter((array) ($stored['group_ids'] ?? []), 'is_numeric'))),
        ];
    }

    /** @return list<?string> */
    public static function defaultPanels(): array
    {
        $registry = app(MonitorRegistry::class);

        return array_map(fn (array $candidates) => collect($candidates)->first(fn ($key) => $registry->isAvailable($key)), self::DEFAULTS);
    }

    /**
     * @param  list<?string>  $panels  content per field; empty means an empty field
     * @param  list<int|string>  $groupIds
     */
    public static function save(int $fields, array $panels, array $groupIds): void
    {
        if (! in_array($fields, self::FIELD_COUNTS, true)) {
            throw ValidationException::withMessages(['fields' => 'Bitte 1, 2 oder 4 Felder wählen.']);
        }

        $registry = app(MonitorRegistry::class);
        $panels = array_pad(array_slice($panels, 0, self::MAX_FIELDS), self::MAX_FIELDS, null);
        foreach ($panels as $i => $key) {
            if (filled($key) && ! $registry->isAvailable((string) $key)) {
                throw ValidationException::withMessages(["panels.$i" => 'Diesen Inhalt gibt es nicht (mehr).']);
            }
            $panels[$i] = filled($key) ? (string) $key : null;
        }

        $groupIds = array_values(array_map('intval', array_filter($groupIds, 'is_numeric')));
        $groupIds = Group::whereIn('id', $groupIds)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        Setting::set(self::KEY, json_encode(['fields' => $fields, 'panels' => $panels, 'group_ids' => $groupIds]));
    }

    /** "full", "half" or "quarter": how much of the screen one field gets */
    public static function sizeFor(int $fields): string
    {
        return match ($fields) {
            1 => 'full',
            2 => 'half',
            default => 'quarter',
        };
    }

    /**
     * The Abteilungen for overviews: the chosen ones, else all, in their order.
     *
     * @param  list<int>  $groupIds
     * @return Collection<int, Group>
     */
    public static function groups(array $groupIds)
    {
        return Group::query()
            ->when($groupIds !== [], fn ($q) => $q->whereIn('id', $groupIds))
            ->orderBy('sort_order')->orderBy('name')->get();
    }
}
