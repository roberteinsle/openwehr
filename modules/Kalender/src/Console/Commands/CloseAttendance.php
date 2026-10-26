<?php

namespace Modules\Kalender\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Kalender\src\Services\AttendanceService;

class CloseAttendance extends Command
{
    protected $signature = 'kalender:close-attendance';

    protected $description = 'Send the attendance of Dienste over for 24 hours to the Wehrführung, or remind when none is recorded';

    public function handle(AttendanceService $attendance): int
    {
        $done = $attendance->closeDue();
        $this->info($done['sent'].' attendance list(s) sent, '.$done['reminded'].' reminder(s)');

        return self::SUCCESS;
    }
}
