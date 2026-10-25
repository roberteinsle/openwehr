<?php

use App\Services\SystemStatus;
use Illuminate\Support\Facades\Route;

// A backup job reports a finished backup (admin "Analyse"): GET or POST, optionally ?status=failed and ?note=…
// No session, no CSRF; the secret token in the URL is the key (shown to admins under Analyse)
Route::match(['GET', 'POST'], '/webhooks/backup/{token}', function (string $token, SystemStatus $status) {
    abort_unless(hash_equals($status->backupToken(), $token), 404);
    $ok = ! in_array(strtolower((string) request()->input('status', 'ok')), ['failed', 'error', 'fehler', '0', 'false'], true);
    $status->reportBackup('Webhook', $ok, request()->input('note'));

    return response()->json(['ok' => true]);
})->where('token', '[A-Za-z0-9]{20,80}')->middleware('throttle:20,1')->name('webhooks.backup');
