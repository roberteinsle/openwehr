<?php

namespace App\Http\Controllers;

use App\Models\Alarm;
use App\Services\AlarmService;
use Illuminate\Http\Request;

/**
 * POST /webhooks/alarm/{key}: DIVERA 24/7's "Webhook – POST application/json". No login, the secret
 * key is the protection (404 otherwise, also while the webhook is off). Answers at once and locates
 * the alarm and tells the modules after the response, so DIVERA never waits.
 */
class AlarmWebhookController
{
    public function __invoke(Request $request, string $key, AlarmService $alarms)
    {
        $expected = AlarmService::key();
        abort_unless($expected !== null && AlarmService::enabled() && hash_equals($expected, $key), 404);

        $body = trim($request->getContent());
        $payload = $body === '' ? [] : json_decode($body, true, 32);
        if (! is_array($payload)) {
            return response()->json(['ok' => false, 'error' => 'Erwartet wird JSON (DIVERA: „Webhook – POST application/json“).'], 422);
        }

        [$alarm, $isNew] = $alarms->receive($payload);

        dispatch(function () use ($alarm, $isNew) {
            if ($fresh = Alarm::find($alarm->id)) {
                app(AlarmService::class)->handle($fresh, $isNew);
            }
        })->afterResponse();

        return response()->json(['ok' => true]);
    }
}
