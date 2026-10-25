<?php

namespace App\Filament\Resources\LogResource\Pages;

use App\Filament\Resources\LogResource;
use App\Support\Retention;
use Filament\Resources\Pages\ListRecords;

class ListLogs extends ListRecords
{
    protected static string $resource = LogResource::class;

    public function getSubheading(): ?string
    {
        return 'Alle Änderungen in der App – auch die der Kameraden –, Anmeldungen und versendete E-Mails der letzten '.Retention::of('audit').' Tage (Aufbewahrung unter Analyse einstellbar). Passwörter, Schlüssel und Dateiinhalte werden nicht gespeichert; Login- und Bestätigungslinks in E-Mails ebenfalls nicht.';
    }
}
