<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Modules\Atemschutz\AtemschutzServiceProvider;
use Modules\Ausruestung\AusruestungServiceProvider;
use Modules\Einsatzbericht\EinsatzberichtServiceProvider;
use Modules\Expositionen\ExpositionenServiceProvider;
use Modules\Fahrtenbuch\FahrtenbuchServiceProvider;
use Modules\Fahrzeugbuchung\FahrzeugbuchungServiceProvider;
use Modules\Fahrzeuge\FahrzeugeServiceProvider;
use Modules\Finanzen\FinanzenServiceProvider;
use Modules\Fuehrerscheine\FuehrerscheineServiceProvider;
use Modules\Gefahrstoffe\GefahrstoffeServiceProvider;
use Modules\Kalender\KalenderServiceProvider;
use Modules\Kleiderkammer\KleiderkammerServiceProvider;
use Modules\Lehrgaenge\LehrgaengeServiceProvider;
use Modules\Maengelmanagement\MaengelmanagementServiceProvider;
use Modules\Mitteilungen\MitteilungenServiceProvider;
use Modules\Objektplaene\ObjektplaeneServiceProvider;
use Modules\Personalverwaltung\PersonalverwaltungServiceProvider;
use Modules\Schichtplan\SchichtplanServiceProvider;
use Modules\Schluessel\SchluesselServiceProvider;
use Modules\Uebungsdienste\UebungsdiensteServiceProvider;
use Modules\Unterweisungen\UnterweisungenServiceProvider;
use Modules\Verbandbuch\VerbandbuchServiceProvider;
use Modules\Wartung\WartungServiceProvider;
use Modules\Wasserkarte\WasserkarteServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FahrzeugbuchungServiceProvider::class,
    KleiderkammerServiceProvider::class,
    MaengelmanagementServiceProvider::class,
    AusruestungServiceProvider::class,
    SchichtplanServiceProvider::class,
    KalenderServiceProvider::class,
    LehrgaengeServiceProvider::class,
    MitteilungenServiceProvider::class,
    UebungsdiensteServiceProvider::class,
    EinsatzberichtServiceProvider::class,
    FinanzenServiceProvider::class,
    VerbandbuchServiceProvider::class,
    WartungServiceProvider::class,
    AtemschutzServiceProvider::class,
    FahrzeugeServiceProvider::class,
    FahrtenbuchServiceProvider::class,
    WasserkarteServiceProvider::class,
    ObjektplaeneServiceProvider::class,
    PersonalverwaltungServiceProvider::class,
    SchluesselServiceProvider::class,
    ExpositionenServiceProvider::class,
    FuehrerscheineServiceProvider::class,
    UnterweisungenServiceProvider::class,
    GefahrstoffeServiceProvider::class,
];
