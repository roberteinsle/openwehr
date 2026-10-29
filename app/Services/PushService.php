<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Web push to the devices a Kamerad switched it on for (installed app or browser). Every entry
 * under the bell is also pushed, so nothing needs its own push code. The VAPID keys are created
 * on first use and kept in the settings (the private one encrypted). Failures never break the
 * action that sent the notification.
 */
class PushService
{
    /** @return array{publicKey: string, privateKey: string} */
    public function keys(): array
    {
        $stored = Setting::get('push.vapid_keys');
        if ($stored) {
            try {
                return json_decode(Crypt::decryptString($stored), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $keys = VAPID::createVapidKeys();
        Setting::set('push.vapid_keys', Crypt::encryptString(json_encode($keys)));

        return $keys;
    }

    public function publicKey(): string
    {
        return $this->keys()['publicKey'];
    }

    /** @param  array{endpoint?: string, keys?: array{p256dh?: string, auth?: string}}  $subscription  from PushSubscription.toJSON() in the browser */
    public function subscribe(User $user, array $subscription, ?string $device = null): PushSubscription
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $key = (string) ($subscription['keys']['p256dh'] ?? '');
        $auth = (string) ($subscription['keys']['auth'] ?? '');
        if (! str_starts_with($endpoint, 'https://') || strlen($endpoint) > 2000 || $key === '' || strlen($key) > 200 || $auth === '' || strlen($auth) > 100) {
            throw ValidationException::withMessages(['push' => 'Dieses Gerät hat keine gültige Push-Anmeldung geliefert.']);
        }

        return PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $endpoint)], [
            'user_id' => $user->id, 'endpoint' => $endpoint, 'public_key' => $key, 'auth_token' => $auth,
            'device' => $device ? Str::limit($device, 140, '') : null,
        ]);
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::where('user_id', $user->id)->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    /** Sends to every device of the Kamerad; devices the push service no longer knows are removed */
    public function toUser(User $user, string $title, string $body, ?string $url = null): int
    {
        $subscriptions = PushSubscription::where('user_id', $user->id)->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $payload = json_encode(['title' => Str::limit($title, 120), 'body' => Str::limit($body, 240), 'url' => $url ?: url('/')], JSON_UNESCAPED_UNICODE);
        try {
            $results = $this->deliver($subscriptions, $payload);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        $gone = collect($results)->filter(fn ($ok) => $ok === 'gone')->keys();
        PushSubscription::whereIn('id', $gone)->delete();
        PushSubscription::whereIn('id', collect($results)->filter(fn ($ok) => $ok === true)->keys())->update(['last_used_at' => now()]);

        return collect($results)->filter(fn ($ok) => $ok === true)->count();
    }

    /**
     * The HTTP client for the push services (web-push 11 takes a PSR-18 client instead of a timeout).
     * The logger keeps the library's hints (e.g. "install GMP") out of trigger_error, which Laravel turns into exceptions.
     */
    protected function client(): ClientInterface
    {
        return new Client(['timeout' => 10, 'connect_timeout' => 5]);
    }

    /** @return array<int, true|false|'gone'> by subscription id */
    protected function deliver(Collection $subscriptions, string $payload): array
    {
        $keys = $this->keys();
        $subject = str_starts_with((string) config('app.url'), 'https://') ? config('app.url') : 'mailto:'.config('mail.from.address');
        $push = new WebPush(['VAPID' => ['subject' => $subject, 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]], ['TTL' => 86400], client: $this->client(), logger: app('log'));
        $byEndpoint = $subscriptions->keyBy('endpoint');
        foreach ($subscriptions as $s) {
            // aes128gcm (RFC 8291): the encoding every current browser supports, and the only one Apple's push service takes
            $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->public_key, 'authToken' => $s->auth_token, 'contentEncoding' => 'aes128gcm']), $payload);
        }

        $results = [];
        foreach ($push->flush() as $report) {
            $subscription = $byEndpoint->get($report->getEndpoint());
            if ($subscription) {
                $results[$subscription->id] = $report->isSuccess() ? true : ($report->isSubscriptionExpired() ? 'gone' : false);
            }
        }

        return $results;
    }
}
