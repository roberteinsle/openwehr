<?php

use App\Livewire\Profile\PushSettings;
use App\Models\Contact;
use App\Models\Group;
use App\Models\Module;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Services\PushService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Mitteilungen\src\Livewire\Manage;
use Psr\Http\Client\ClientInterface;

/** Records what would go to the push services; the endpoint "gone" is unknown there */
class FakePushService extends PushService
{
    public array $sent = [];

    protected function deliver(Collection $subscriptions, string $payload): array
    {
        $this->sent[] = json_decode($payload, true);

        return $subscriptions->mapWithKeys(fn ($s) => [$s->id => str_contains($s->endpoint, 'gone') ? 'gone' : true])->all();
    }
}

function pushSubscription(string $endpoint = 'https://push.example.com/abc'): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyOfTheBrowser', 'auth' => 'authsecret']];
}

beforeEach(function () {
    $this->push = new FakePushService;
    app()->instance(PushService::class, $this->push);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
});

test('a device is switched on in the profile; every entry under the bell goes to it as push', function () {
    Livewire::actingAs($this->hanna)->test(PushSettings::class)
        ->assertSee('Push-Benachrichtigungen')
        ->call('subscribe', pushSubscription(), 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36')
        ->call('subscribe', pushSubscription('https://push.example.com/gone'), 'Mozilla/5.0 (iPhone) Safari/604.1')
        ->assertSee('Chrome auf Android');
    expect(PushSubscription::where('user_id', $this->hanna->id)->count())->toBe(2);

    // The same endpoint again is one device, not two
    Livewire::actingAs($this->hanna)->test(PushSettings::class)->call('subscribe', pushSubscription(), null);
    expect(PushSubscription::count())->toBe(2);

    Module::where('key', 'mitteilungen')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->hanna->moduleAccess()->create(['module' => 'mitteilungen']);
    $editor = User::factory()->create(['is_admin' => true]);

    // A short notice: bell and push, no mail
    Livewire::actingAs($editor)->test(Manage::class)
        ->set('title', 'Dienst heute erst um 19:30')->set('push', true)->call('save')->assertSee('als Push verschickt');

    expect($this->push->sent)->toHaveCount(1)
        ->and($this->push->sent[0]['title'])->toBe('Mitteilung: Dienst heute erst um 19:30')
        ->and($this->hanna->notifications()->count())->toBe(1)
        // The device the push service no longer knows is gone
        ->and(PushSubscription::pluck('endpoint')->all())->toBe(['https://push.example.com/abc']);

    // Switched off on the device
    Livewire::actingAs($this->hanna)->test(PushSettings::class)->call('unsubscribe', 'https://push.example.com/abc');
    expect(PushSubscription::count())->toBe(0);
});

test('invalid subscriptions are refused; keys are created once and kept encrypted', function () {
    Livewire::actingAs($this->hanna)->test(PushSettings::class)
        ->call('subscribe', ['endpoint' => 'http://insecure.example.com', 'keys' => ['p256dh' => 'x', 'auth' => 'y']], null)
        ->assertHasErrors('push');
    expect(PushSubscription::count())->toBe(0);

    $key = $this->push->publicKey();
    expect($this->push->publicKey())->toBe($key)
        ->and(Setting::get('push.vapid_keys'))->not->toContain($key);
});

test('app manifest, service worker and offline page', function () {
    $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('display', 'standalone')->assertJsonPath('start_url', '/')->assertJsonPath('icons.0.src', '/images/icon-192.png');
    $this->get('/offline')->assertOk()->assertSee('Keine Verbindung');
    expect(file_exists(public_path('sw.js')))->toBeTrue();
    $this->actingAs($this->hanna)->get(route('profile'))->assertSee('manifest.webmanifest', false)->assertSee('Push-Benachrichtigungen');
});

test('Ansprechpartner: everyone sees them with phone to tap, grouped by Abteilung', function () {
    $this->actingAs($this->hanna)->get(route('contacts'))->assertOk()->assertSee('Noch keine Ansprechpartner');

    $jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    Contact::create(['role' => 'Wehrführer', 'name' => 'Bernd Blaulicht', 'phone' => '0170 123 45 67', 'sort_order' => 1]);
    Contact::create(['role' => 'Jugendwartin', 'name' => 'Hanna Hydrant', 'phone' => '+49 (40) 555-12', 'email' => 'jugend@example.com', 'group_id' => $jf->id, 'sort_order' => 2]);

    $this->get(route('contacts'))->assertOk()
        ->assertSeeInOrder(['Wehrführer', 'Bernd Blaulicht', 'Jugendfeuerwehr', 'Jugendwartin'])
        ->assertSee('href="tel:01701234567"', false)->assertSee('href="tel:+494055512"', false);
    $this->get(route('home'))->assertSee(route('contacts'));
});

test('the real delivery encrypts and sends through the HTTP client and recognises expired devices', function () {
    $handler = new MockHandler([new Response(201), new Response(410)]);
    $requests = [];
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($requests));
    $service = new class($stack) extends PushService
    {
        public function __construct(private readonly HandlerStack $stack) {}

        protected function client(): ClientInterface
        {
            return new Client(['handler' => $this->stack]);
        }
    };
    app()->instance(PushService::class, $service);

    // Keys like a browser sends them: an uncompressed P-256 point and a 16-byte auth secret
    $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $key = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]))['ec'];
    $browser = fn (string $endpoint) => ['endpoint' => $endpoint, 'keys' => ['p256dh' => $b64("\x04".str_pad($key['x'], 32, "\0", STR_PAD_LEFT).str_pad($key['y'], 32, "\0", STR_PAD_LEFT)), 'auth' => $b64(random_bytes(16))]];
    $service->subscribe($this->hanna, $browser('https://web.push.apple.com/ok'), 'Mozilla/5.0 (iPhone) Safari/604.1');
    $service->subscribe($this->hanna, $browser('https://web.push.apple.com/expired'), 'Mozilla/5.0 (iPhone) Safari/604.1');

    expect($service->toUser($this->hanna, 'Test', 'Hallo'))->toBe(1)
        ->and($requests)->toHaveCount(2)
        ->and($requests[0]['request']->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($requests[0]['request']->getHeaderLine('Authorization'))->toStartWith('vapid t=')
        ->and(PushSubscription::pluck('endpoint')->all())->toBe(['https://web.push.apple.com/ok']);
});
