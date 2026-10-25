<?php

namespace Modules\Gefahrstoffe\src\Support;

/**
 * The Gefahrnummern of the ADR (data/gefahrnummern.php) and the Gefahrenmatrix. Read only, nothing is stored;
 * the lookup page gets everything at once so it also works offline.
 */
class HazardNumbers
{
    /** @var ?array{edition: string, digits: array<string, string>, numbers: array<string, string>} */
    private static ?array $data = null;

    /** @return array{edition: string, digits: array<string, string>, numbers: array<string, string>} */
    public static function data(): array
    {
        return self::$data ??= require __DIR__.'/../../data/gefahrnummern.php';
    }

    /** "x 33" → "X33"; null for anything that can't be a Gefahrnummer */
    public static function normalize(string $input): ?string
    {
        $code = strtoupper(preg_replace('/\s+/', '', $input) ?? '');

        return preg_match('/^X?[2-9][0-9]{1,2}$/', $code) ? $code : null;
    }

    /**
     * What a Gefahrnummer means: the ADR text when it is listed, otherwise read from its digits.
     *
     * @return ?array{code: string, meaning: ?string, digits: list<string>, water: bool}
     */
    public static function lookup(string $input): ?array
    {
        if (! $code = self::normalize($input)) {
            return null;
        }
        $data = self::data();
        $digits = [];
        foreach (array_unique(str_split(ltrim($code, 'X'))) as $digit) {
            if (isset($data['digits'][$digit])) {
                $digits[] = $data['digits'][$digit];
            }
        }

        return ['code' => $code, 'meaning' => $data['numbers'][$code] ?? null, 'digits' => $digits, 'water' => str_starts_with($code, 'X')];
    }

    /**
     * Warntafeln written in a free text, e.g. "Benzin 33/1203" or "X423 – 1428".
     *
     * @return list<array{code: string, un: string, meaning: ?string, digits: list<string>, water: bool}>
     */
    public static function panelsIn(?string $text): array
    {
        preg_match_all('/\b(X?\s?[2-9][0-9]{1,2})\s*[\/\-–]\s*([0-9]{4})\b/iu', (string) $text, $matches, PREG_SET_ORDER);
        $panels = [];
        foreach ($matches as [, $code, $un]) {
            if ($found = self::lookup($code)) {
                $panels[] = $found + ['un' => $un];
            }
        }

        return $panels;
    }

    /** The Gefahrenmatrix: the hazards (AAAA-C-EEEE) and what they threaten; texts written for openwehr */
    public const HAZARDS = [
        'A1' => ['Atemgifte', 'Rauch, Brandgase, Dämpfe, Sauerstoffmangel – alles, was über die Atemwege schadet.'],
        'A2' => ['Angstreaktion', 'Panik und unüberlegtes Handeln bei Betroffenen, Zuschauern und eigenen Kräften.'],
        'A3' => ['Ausbreitung', 'Feuer, Rauch, auslaufende Stoffe oder Wasser greifen auf weitere Bereiche über.'],
        'A4' => ['Atomare Gefahren', 'Ionisierende Strahlung radioaktiver Stoffe (Warnzeichen Strahlung, Klasse 7).'],
        'C' => ['Chemische Gefahren', 'Gefährliche Stoffe: giftig, ätzend, entzündbar, brandfördernd, umweltgefährdend.'],
        'E1' => ['Erkrankung / Verletzung', 'Krankheitserreger, Verletzungen durch Splitter, scharfe Kanten, Hitze oder Kälte.'],
        'E2' => ['Explosion', 'Gase, Dämpfe, Stäube, Druckbehälter, Explosivstoffe.'],
        'E3' => ['Elektrizität', 'Stromführende Teile, Hochvolt-Fahrzeuge, Photovoltaik, Freileitungen.'],
        'E4' => ['Einsturz', 'Einsturz und Absturz: Gebäudeteile, Decken, Gräben, Ladung, Bäume.'],
    ];

    public const TARGETS = ['Menschen', 'Tiere', 'Umwelt', 'Sachwerte', 'Mannschaft', 'Gerät'];
}
