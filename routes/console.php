<?php

use App\Models\ActivityLog;
use App\Models\AppError;
use App\Models\EmailChangeRequest;
use App\Models\Heartbeat;
use App\Models\LoginToken;
use App\Models\MailLog;
use App\Models\RequestMetric;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Schedule;
use Modules\Einsatzbericht\src\Models\DiveraRequest;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Verbandbuch\src\Models\Entry as VerbandbuchEntry;

// Scheduled commands write to /dev/null by default. In the container their output and
// errors go to the container log instead, otherwise to storage/logs/schedule.log.
$output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

// Prune expired login tokens, e-mail change requests, mail log and activity log after their retention (Analyse), notifications after 180 days, DIVERA requests after 90 days,
// technical logs (errors, request figures, heartbeats) after their retention,
// Verbandbuch entries after their retention time daily
Schedule::command('model:prune', ['--model' => [LoginToken::class, EmailChangeRequest::class, MailLog::class, ActivityLog::class, DiveraRequest::class, UserNotification::class, VerbandbuchEntry::class, Exposure::class,
    AppError::class, RequestMetric::class, Heartbeat::class]])->daily()->appendOutputTo($output);

// Admin "Analyse": the app is up this minute; daily certificate, backup and job check with a mail to the admins
Schedule::command('app:heartbeat')->everyMinute()->appendOutputTo($output);
Schedule::command('app:health-check')->dailyAt('07:15')->timezone('Europe/Berlin')->appendOutputTo($output);
