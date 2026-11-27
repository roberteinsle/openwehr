<?php

use App\Models\Setting;
use App\Support\MonitorSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * The Wachen-Monitor's default became three columns (V.0.9.7.5). A layout that was saved but still is the
 * old default – four fields with Dienste/Termine per Abteilung, Fahrzeugbelegung, Mitteilungen and next
 * Termine, e.g. saved only to change the refresh interval – moves to the new default. Layouts an admin
 * really changed stay as they are; Abteilungen and refresh interval are kept.
 */
return new class extends Migration
{
    /** The old default per field: one of these or empty */
    private const OLD_DEFAULT = [
        ['uebungsdienste.abteilungen', 'kalender.abteilungen'],
        ['fahrzeugbuchung.belegung'],
        ['mitteilungen.aktuell'],
        ['kalender.termine'],
    ];

    public function up(): void
    {
        $stored = json_decode((string) Setting::where('key', MonitorSettings::KEY)->value('value'), true);
        if (! is_array($stored) || (int) ($stored['fields'] ?? 0) !== 4 || ! is_array($stored['panels'] ?? null)) {
            return;
        }

        $panels = array_pad(array_values($stored['panels']), 4, null);
        foreach (self::OLD_DEFAULT as $i => $candidates) {
            if ($panels[$i] !== null && ! in_array($panels[$i], $candidates, true)) {
                return;
            }
        }

        Setting::set(MonitorSettings::KEY, json_encode([
            ...$stored,
            'fields' => MonitorSettings::DEFAULT_FIELDS,
            'panels' => MonitorSettings::defaultPanels(),
        ]));
    }

    public function down(): void
    {
        // Nothing to undo: the admin can choose four fields again
    }
};
