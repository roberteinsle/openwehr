<?php

namespace App\Filament\Resources\GuardianResource\Pages;

use App\Filament\Resources\GuardianResource;
use Filament\Resources\Pages\ListRecords;

class ListGuardians extends ListRecords
{
    protected static string $resource = GuardianResource::class;

    public function getSubheading(): ?string
    {
        return 'Erziehungsberechtigte werden beim Kind gepflegt: Kamerad bearbeiten → Erziehungsberechtigte.';
    }
}
