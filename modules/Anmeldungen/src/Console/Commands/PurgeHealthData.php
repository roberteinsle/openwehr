<?php

namespace Modules\Anmeldungen\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Anmeldungen\src\Services\RegistrationService;

class PurgeHealthData extends Command
{
    protected $signature = 'anmeldungen:purge-health';

    protected $description = 'Löscht die Gesundheitsangaben der Anmeldungen, deren Veranstaltung lange genug vorbei ist';

    public function handle(RegistrationService $service): int
    {
        $count = $service->purgeHealth();
        $this->info($count.' Anmeldungen bereinigt.');

        return self::SUCCESS;
    }
}
