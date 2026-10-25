<?php

namespace App\Console\Commands;

use App\Services\SystemStatus;
use Illuminate\Console\Command;

/** Daily: TLS certificate, backup age, failed scheduled jobs, disk – the admins get one mail if something is wrong */
class HealthCheck extends Command
{
    protected $signature = 'app:health-check';

    protected $description = 'Checks certificate, backups, scheduled jobs and disk and tells the admins';

    public function handle(SystemStatus $status): int
    {
        $count = $status->dailyCheck();
        $this->info($count === 0 ? 'Alles in Ordnung.' : $count.' Punkt(e) gemeldet.');

        return self::SUCCESS;
    }
}
