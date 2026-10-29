<?php

use App\Livewire\Auth\LoginForm;
use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Services\Invitation;
use App\Services\MagicLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    RateLimiter::clear('magic-link:ip:127.0.0.1');
    RateLimiter::clear('magic-link:ip:');
});

test('magic link form is accessible', function () {
    $this->get(route('auth.magic-link.form'))
        ->assertOk();
});

test('sending magic link shows sent page', function () {
    $user = User::factory()->create();

    $this->post(route('auth.magic-link.send'), ['email' => $user->email])
        ->assertRedirect(route('auth.magic-link.sent'));
});

test('sending link to unknown email still redirects (no enumeration)', function () {
    $this->post(route('auth.magic-link.send'), ['email' => 'nonexistent@example.com'])
        ->assertRedirect(route('auth.magic-link.sent'));
});

test('GET confirm route does not consume token', function () {
    $user = User::factory()->create();
    $rawToken = Str::random(64);

    LoginToken::factory()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->get(route('auth.magic-link.confirm', $rawToken))
        ->assertOk();

    // Token must NOT be consumed — used_at stays null
    $this->assertDatabaseHas('login_tokens', [
        'token_hash' => hash('sha256', $rawToken),
        'used_at' => null,
    ]);
});

test('POST confirm route logs in user and marks token used', function () {
    $user = User::factory()->create(['last_login_at' => now()->subDay()]);   // not the first login (that one goes to Willkommen)
    $rawToken = Str::random(64);

    LoginToken::factory()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->post(route('auth.magic-link.consume', $rawToken))
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);

    $this->assertDatabaseMissing('login_tokens', [
        'token_hash' => hash('sha256', $rawToken),
        'used_at' => null,
    ]);
});

test('expired token is rejected', function () {
    $user = User::factory()->create();
    $rawToken = Str::random(64);

    LoginToken::factory()->expired()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
    ]);

    $this->post(route('auth.magic-link.consume', $rawToken))
        ->assertRedirect(route('auth.magic-link.form'));

    $this->assertGuest();
});

test('used token cannot be reused', function () {
    $user = User::factory()->create();
    $rawToken = Str::random(64);

    LoginToken::factory()->used()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->post(route('auth.magic-link.consume', $rawToken))
        ->assertRedirect(route('auth.magic-link.form'));

    $this->assertGuest();
});

test('inactive user cannot log in', function () {
    $user = User::factory()->inactive()->create();
    $rawToken = Str::random(64);

    LoginToken::factory()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->post(route('auth.magic-link.consume', $rawToken))
        ->assertRedirect();

    $this->assertGuest();
});

test('rate limit blocks excessive requests per email', function () {
    $user = User::factory()->create();
    $key = 'magic-link:email:'.hash('sha256', strtolower($user->email));

    // Exhaust the limit
    RateLimiter::hit($key, 3600);
    RateLimiter::hit($key, 3600);
    RateLimiter::hit($key, 3600);
    RateLimiter::hit($key, 3600);
    RateLimiter::hit($key, 3600);

    $this->post(route('auth.magic-link.send'), ['email' => $user->email])
        ->assertRedirect(); // still redirects (no enumeration), but no token created

    $this->assertDatabaseCount('login_tokens', 0);
});

test('login form component sends magic link', function () {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::test(LoginForm::class)
        ->set('email', $user->email)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    Notification::assertSentTo($user, MagicLinkNotification::class);
});

test('login form component validates email', function () {
    Livewire::test(LoginForm::class)
        ->set('email', 'not-an-email')
        ->call('submit')
        ->assertHasErrors(['email' => 'email'])
        ->assertSet('sent', false);
});

// SQL injection: payloads that fail the e-mail rule and payloads that are valid RFC addresses (and so reach the lookup)
dataset('sqli emails', [
    "' OR '1'='1",
    "' OR 1=1 --",
    "admin'--",
    "'; DROP TABLE users; --",
    '" OR ""="',
    "1' UNION SELECT NULL--",
    '%27%20OR%20%271%27%3D%271',
    "admin'--@example.com",
    "' OR '1'='1'@example.com",
    "\"'; DROP TABLE users; --\"@example.com",
    "\"' OR 1=1 --\"@example.com",
    "x'UNION/**/SELECT/**/NULL--@example.com",
]);

dataset('sqli tokens', [
    "' OR '1'='1",
    "' OR 1=1 --",
    "admin'--",
    "'; DROP TABLE users; --",
    '" OR ""="',
    "1' UNION SELECT NULL--",
    '%27%20OR%20%271%27%3D%271',
    str_repeat('a', 63)."'",
    "' OR '1'='1".str_repeat('a', 53),
]);

test('SQL injection in the e-mail field logs no one in, sends nothing and breaks nothing', function (string $payload) {
    Notification::fake();
    User::factory()->admin()->create(['email' => 'admin@example.com']);
    $users = User::count();

    $response = $this->post(route('auth.magic-link.send'), ['email' => $payload]);

    expect($response->status())->toBe(302);
    $this->followRedirects($response)->assertOk()->assertDontSee('SQLSTATE')->assertDontSee('syntax error');
    $this->assertGuest();
    Notification::assertNothingSent();
    expect(LoginToken::count())->toBe(0)->and(User::count())->toBe($users);
})->with('sqli emails');

test('SQL injection through the Livewire login form is harmless too', function (string $payload) {
    Notification::fake();
    User::factory()->admin()->create(['email' => 'admin@example.com']);

    Livewire::test(LoginForm::class)->set('email', $payload)->call('submit')->assertOk();

    $this->assertGuest();
    Notification::assertNothingSent();
    expect(LoginToken::count())->toBe(0)->and(User::where('email', 'admin@example.com')->exists())->toBeTrue();
})->with('sqli emails');

test('SQL injection in the token is a 404 before any database query', function (string $payload) {
    $user = User::factory()->create();
    LoginToken::factory()->create(['user_id' => $user->id]);

    $this->get('/anmelden/'.rawurlencode($payload))->assertNotFound()->assertDontSee('SQLSTATE');
    $this->post('/anmelden/'.rawurlencode($payload))->assertNotFound()->assertDontSee('SQLSTATE');
    $this->assertGuest();
    expect(LoginToken::whereNull('used_at')->count())->toBe(1);
})->with('sqli tokens');

test('a token of the wrong format is refused without asking the database', function (string $token) {
    $this->get('/anmelden/'.$token)->assertNotFound();
    $this->post('/anmelden/'.$token)->assertNotFound();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    expect(fn () => app(MagicLinkService::class)->consumeToken($token, Request::create('/', 'POST')))->toThrow(ValidationException::class);
    expect($queries)->toBe(0);
})->with([
    'too short' => [str_repeat('a', 63)],
    'too long' => [str_repeat('a', 65)],
    'hyphen' => [str_repeat('a', 63).'-'],
    'umlaut' => [str_repeat('a', 63).'ä'],
]);

test('a manipulated token does not log in and leaves the real one valid', function () {
    $user = User::factory()->create(['last_login_at' => now()->subDay()]);
    $raw = Str::random(64);
    LoginToken::factory()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $raw)]);
    $forged = substr($raw, 0, 63).($raw[63] === 'a' ? 'b' : 'a');

    $this->post(route('auth.magic-link.consume', $forged))->assertRedirect(route('auth.magic-link.form'))->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post(route('auth.magic-link.consume', $raw))->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

test('an expired or used link shows the same message and no SQL details', function () {
    $user = User::factory()->create();
    $expired = Str::random(64);
    $used = Str::random(64);
    LoginToken::factory()->expired()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $expired)]);
    LoginToken::factory()->used()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $used)]);

    foreach ([$expired, $used] as $raw) {
        $this->followingRedirects()->post(route('auth.magic-link.consume', $raw))
            ->assertOk()->assertSee('Dieser Link ist ungültig oder abgelaufen')->assertDontSee('SQLSTATE');
        $this->assertGuest();
    }
});

test('known and unknown addresses get the same answer', function () {
    Notification::fake();
    User::factory()->create(['email' => 'hanna@example.com']);

    $known = $this->post(route('auth.magic-link.send'), ['email' => 'hanna@example.com']);
    $unknown = $this->post(route('auth.magic-link.send'), ['email' => 'niemand@example.com']);

    expect($known->status())->toBe($unknown->status())
        ->and($known->headers->get('Location'))->toBe($unknown->headers->get('Location'));
    $known->assertSessionHasNoErrors();
    $unknown->assertSessionHasNoErrors();

    Livewire::test(LoginForm::class)->set('email', 'hanna@example.com')->call('submit')->assertHasNoErrors()->assertSet('sent', true);
    Livewire::test(LoginForm::class)->set('email', 'niemand@example.com')->call('submit')->assertHasNoErrors()->assertSet('sent', true);
});

test('the lookup, the token and the mail happen after the response (no timing difference)', function () {
    $this->withDefer();
    Notification::fake();
    $user = User::factory()->create(['email' => 'hanna@example.com']);

    app(MagicLinkService::class)->sendLink('hanna@example.com', Request::create('/anmelden', 'POST'));
    expect(LoginToken::count())->toBe(0);
    Notification::assertNothingSent();

    app(DeferredCallbackCollection::class)->invoke();
    expect(LoginToken::where('user_id', $user->id)->count())->toBe(1);
    Notification::assertSentTo($user, MagicLinkNotification::class);
});

test('the address is found regardless of case and surrounding spaces', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'hanna@example.com']);

    $this->post(route('auth.magic-link.send'), ['email' => '  Hanna@Example.COM '])->assertRedirect(route('auth.magic-link.sent'));
    Livewire::test(LoginForm::class)->set('email', ' HANNA@example.com')->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    Notification::assertSentToTimes($user, MagicLinkNotification::class, 2);
});

test('an overlong address is refused before the lookup', function () {
    Notification::fake();
    $this->post(route('auth.magic-link.send'), ['email' => str_repeat('a', 250).'@example.com'])->assertSessionHasErrors('email');
    expect(LoginToken::count())->toBe(0);
});

test('the rate limit per address applies to known and unknown addresses alike', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'hanna@example.com']);

    foreach (['hanna@example.com', 'niemand@example.com'] as $email) {
        foreach (range(1, 5) as $i) {
            $this->post(route('auth.magic-link.send'), ['email' => $email])->assertSessionHasNoErrors();
        }
        $this->post(route('auth.magic-link.send'), ['email' => $email])->assertSessionHasErrors('email');
    }
    // Other spellings of the same address count against the same limit
    $this->post(route('auth.magic-link.send'), ['email' => 'HANNA@example.com'])->assertSessionHasErrors('email');

    Notification::assertSentToTimes($user, MagicLinkNotification::class, 5);
});

test('the rate limit per IP applies', function () {
    Notification::fake();
    foreach (range(1, 20) as $i) {
        $this->post(route('auth.magic-link.send'), ['email' => "kamerad{$i}@example.com"])->assertSessionHasNoErrors();
    }
    $this->post(route('auth.magic-link.send'), ['email' => 'kamerad21@example.com'])->assertSessionHasErrors('email');
});

test('guessing tokens is throttled', function () {
    foreach (range(1, 20) as $i) {
        $this->get(route('auth.magic-link.confirm', Str::random(64)))->assertOk();
    }
    $this->get(route('auth.magic-link.confirm', Str::random(64)))->assertTooManyRequests();
    $this->post(route('auth.magic-link.consume', Str::random(64)))->assertTooManyRequests();
});

test('a login ends every other open link of the Kamerad, not those of others', function () {
    $user = User::factory()->create(['last_login_at' => now()->subDay()]);
    $other = User::factory()->create();
    $raw = Str::random(64);
    LoginToken::factory()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $raw)]);
    $older = Str::random(64);
    LoginToken::factory()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $older)]);
    $invite = app(Invitation::class)->create($user)['url'];
    LoginToken::factory()->create(['user_id' => $other->id]);

    $this->post(route('auth.magic-link.consume', $raw))->assertRedirect(route('home'));
    expect(LoginToken::where('user_id', $user->id)->whereNull('used_at')->count())->toBe(0)
        ->and(LoginToken::where('user_id', $other->id)->whereNull('used_at')->count())->toBe(1);

    auth()->logout();
    $this->post(route('auth.magic-link.consume', $older))->assertRedirect(route('auth.magic-link.form'));
    $this->post($invite)->assertRedirect(route('auth.magic-link.form'));
    $this->assertGuest();
});

test('the session id changes on login (no session fixation)', function () {
    $user = User::factory()->create(['last_login_at' => now()->subDay()]);
    $raw = Str::random(64);
    LoginToken::factory()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $raw)]);

    $this->get(route('auth.magic-link.confirm', $raw));
    $before = session()->getId();
    $this->post(route('auth.magic-link.consume', $raw));

    expect(session()->getId())->not->toBe($before);
    $this->assertAuthenticatedAs($user);
});

test('the confirm page keeps the token out of the referrer of other sites', function () {
    $this->get(route('auth.magic-link.confirm', Str::random(64)))
        ->assertOk()->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('an Einladung on screen holds for half an hour, by mail for two days', function () {
    Notification::fake();
    $this->freezeTime();
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();

    expect(app(Invitation::class)->create($user)['expires_at']->equalTo(now()->addMinutes(Invitation::SCREEN_MINUTES)))->toBeTrue();
    expect(app(Invitation::class)->send($user, $admin)->equalTo(now()->addHours(Invitation::MAIL_HOURS)))->toBeTrue();
    Notification::assertSentTo($user, InvitationNotification::class);
});

/** The code of the last login mail to $user */
function loginCode(User $user): string
{
    $code = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function ($n) use (&$code) {
        $code = (fn () => $this->code)->call($n);

        return true;
    });

    return $code;
}

test('the mail carries a code that logs in inside the app on the home screen', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'hanna@example.com', 'last_login_at' => now()->subDay()]);

    $form = Livewire::test(LoginForm::class)->set('email', 'Hanna@example.com')->call('submit')->assertSet('sent', true)
        ->assertSee('Oder den Code aus der Mail eingeben');
    $code = loginCode($user);
    expect($code)->toMatch('/^\d{6}$/');

    $mail = (new MagicLinkNotification('x', $code))->toMail($user);
    expect(implode(' ', $mail->introLines).implode(' ', $mail->outroLines))->toContain($code)
        ->and($mail->subject)->not->toContain($code);
    expect(LoginToken::sole()->code_hash)->not->toBe($code)->not->toBeNull();

    $form->set('code', substr($code, 0, 3).' '.substr($code, 3))->call('verify')->assertHasNoErrors()->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
    // Code and link are the same token: both are used up now
    expect(LoginToken::whereNull('used_at')->count())->toBe(0);
});

test('a wrong code does not log in, and after five wrong tries the code is dead but the link still works', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'hanna@example.com', 'last_login_at' => now()->subDay()]);
    $form = Livewire::test(LoginForm::class)->set('email', 'hanna@example.com')->call('submit');
    $code = loginCode($user);
    $wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);

    foreach (range(1, MagicLinkService::CODE_ATTEMPTS) as $i) {
        $form->set('code', $wrong)->call('verify')->assertHasErrors('code');
    }
    $form->set('code', $code)->call('verify')->assertHasErrors('code');
    $this->assertGuest();

    $raw = null;
    Notification::assertSentTo($user, MagicLinkNotification::class, function ($n) use (&$raw) {
        $raw = (fn () => $this->rawToken)->call($n);

        return true;
    });
    $this->post(route('auth.magic-link.consume', $raw))->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

test('a code only works with the address it was sent to, not after expiry and not twice', function () {
    Notification::fake();
    $hanna = User::factory()->create(['email' => 'hanna@example.com', 'last_login_at' => now()->subDay()]);
    User::factory()->create(['email' => 'bernd@example.com']);
    $form = Livewire::test(LoginForm::class)->set('email', 'hanna@example.com')->call('submit');
    $code = loginCode($hanna);

    Livewire::test(LoginForm::class)->set('email', 'bernd@example.com')->set('sent', true)->set('code', $code)->call('verify')->assertHasErrors('code');
    Livewire::test(LoginForm::class)->set('email', 'niemand@example.com')->set('sent', true)->set('code', $code)->call('verify')->assertHasErrors('code');
    $this->assertGuest();

    $this->travel(16)->minutes();
    $form->set('code', $code)->call('verify')->assertHasErrors('code');
    $this->assertGuest();
    $this->travelBack();

    // A fresh request: works once
    RateLimiter::clear('magic-code:email:'.hash('sha256', 'hanna@example.com'));
    Notification::fake();
    $form = Livewire::test(LoginForm::class)->set('email', 'hanna@example.com')->call('submit');
    $code = loginCode($hanna);
    $form->set('code', $code)->call('verify')->assertHasNoErrors();
    auth()->logout();
    Livewire::test(LoginForm::class)->set('email', 'hanna@example.com')->set('sent', true)->set('code', $code)->call('verify')->assertHasErrors('code');
    $this->assertGuest();
});

test('malformed codes never reach the database', function () {
    foreach (["' OR '1'='1", '12345', '1234567', 'abcdef', "1' UNION SELECT NULL--"] as $bad) {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        expect(fn () => app(MagicLinkService::class)->consumeCode('hanna@example.com', $bad, Request::create('/', 'POST')))->toThrow(ValidationException::class);
        expect($queries)->toBe(0);
    }
});

test('after ten tries per hour even the right code is refused', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'hanna@example.com']);
    $form = Livewire::test(LoginForm::class)->set('email', 'hanna@example.com')->call('submit');
    $code = loginCode($user);
    foreach (range(1, 10) as $i) {
        RateLimiter::hit('magic-code:email:'.hash('sha256', 'hanna@example.com'), 3600);
    }

    $form->set('code', $code)->call('verify')->assertHasErrors(['code' => 'Zu viele Versuche. Bitte warte etwas oder nutze den Link aus der Mail.']);
    $this->assertGuest();
});

test('the first login by code leads to the Willkommen page; Einladungen have no code', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'nina@example.com', 'last_login_at' => null]);
    app(Invitation::class)->create($user);
    expect(LoginToken::sole()->code_hash)->toBeNull();

    $form = Livewire::test(LoginForm::class)->set('email', 'nina@example.com')->call('submit');
    $form->set('code', loginCode($user))->call('verify')->assertRedirect(route('welcome'));
    $this->assertAuthenticatedAs($user);
    expect(LoginToken::whereNull('used_at')->count())->toBe(0);
});
