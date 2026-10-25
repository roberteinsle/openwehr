<?php

namespace App\Console\Commands;

use App\Services\SystemStatus;
use Illuminate\Console\Command;

/** Every minute: the app is up (uptime in the admin "Analyse") and which version runs since when */
class Heartbeat extends Command
{
    protected $signature = 'app:heartbeat';

    protected $description = 'Notes that the app runs this minute';

    public function handle(SystemStatus $status): int
    {
        $status->heartbeat();

        return self::SUCCESS;
    }
}
