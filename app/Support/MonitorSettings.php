<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Setting;
use App\Services\MonitorRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Layout of the Wachen-Monitor: 1, 2, 3 or 4 fields (3 = columns side by side), the content of each (keys of the MonitorRegistry)
 * and the Abteilungen the overviews show. Stored as JSON in the setting monitor.layout; without
 * one the defaults apply, as far as their modules are on.
 */
final class MonitorSettings
{
    public const KEY = 'monitor.layout';

    public const FIELD_COUNTS = [1, 2, 3, 4];

    public const MAX_FIELDS = 4;

    /** Without saved settings: three columns, Fahrzeugbelegung – Kalender – Mitteilungen */
    public const DEFAULT_FIELDS = 3;

    /** How often the screen reloads its contents, in minutes */
    public const REFRESH_MINUTES = [1, 3, 5, 15, 30, 60];

    public const DEFAULT_REFRESH = 1;

    /** Default content per field: the first one whose module is on */
    private const DEFAULTS = [
        ['fahrzeugbuchung.belegung'],
        ['kalender.tage', 'uebungsdienste.abteilungen'],
        ['mitteilungen.aktuell'],
        ['uebungsdienste.abteilungen', 'kalender.termine'],
    ];

    /** @return array{fields: int, panels: list<?string>, group_ids: list<int>, refresh: int} panels always has MAX_FIELDS entries, refresh in minutes */
    public static function get(): array
    {
        $stored = json_decode((string) Setting::get(self::KEY, ''), true);
        $stored = is_array($stored) ? $stored : [];

        $fields = in_array((int) ($stored['fields'] ?? 0), self::FIELD_COUNTS, true) ? (int) $stored['fields'] : self::DEFAULT_FIELDS;
        $panels = isset($stored['panels']) && is_array($stored['panels'])
            ? array_map(fn ($key) => is_string($key) && $key !== '' ? $key : null, array_values($stored['panels']))
            : self::defaultPanels();

        return [
            'fields' => $fields,
            'panels' => array_pad(array_slice($panels, 0, self::MAX_FIELDS), self::MAX_FIELDS, null),
            'group_ids' => array_values(array_map('intval', array_filter((array) ($stored['group_ids'] ?? []), 'is_numeric'))),
            'refresh' => in_array((int) ($stored['refresh'] ?? 0), self::REFRESH_MINUTES, true) ? (int) $stored['refresh'] : self::DEFAULT_REFRESH,
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
     * @param  ?int  $refresh  minutes between updates; null keeps the current value
     */
    public static function save(int $fields, array $panels, array $groupIds, ?int $refresh = null): void
    {
        if (! in_array($fields, self::FIELD_COUNTS, true)) {
            throw ValidationException::withMessages(['fields' => 'Bitte 1, 2, 3 oder 4 Felder wählen.']);
        }
        $refresh ??= self::get()['refresh'];
        if (! in_array($refresh, self::REFRESH_MINUTES, true)) {
            throw ValidationException::withMessages(['refresh' => 'Bitte 1, 3, 5, 15, 30 oder 60 Minuten wählen.']);
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

        Setting::set(self::KEY, json_encode(['fields' => $fields, 'panels' => $panels, 'group_ids' => $groupIds, 'refresh' => $refresh]));
    }

    /** "jede Minute", "alle 5 Minuten" … */
    public static function refreshLabel(int $minutes): string
    {
        return $minutes === 1 ? 'jede Minute' : ($minutes === 60 ? 'jede Stunde' : 'alle '.$minutes.' Minuten');
    }

    /** "full", "half", "third" or "quarter": how much of the screen one field gets */
    public static function sizeFor(int $fields): string
    {
        return match ($fields) {
            1 => 'full',
            2 => 'half',
            3 => 'third',
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
