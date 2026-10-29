<?php

namespace App\Services;

use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

class MagicLinkService
{
    /** Raw tokens are Str::random(64); anything else is rejected before the database is asked */
    public const TOKEN_PATTERN = '[A-Za-z0-9]{64}';

    /** Digits of the code in the mail, and wrong tries before a code stops working (the link still works) */
    public const CODE_LENGTH = 6;

    public const CODE_ATTEMPTS = 5;

    private const CODE_INVALID = 'Der Code stimmt nicht oder ist abgelaufen. Bitte prüfe ihn oder fordere einen neuen an.';

    public function sendLink(string $email, Request $request): void
    {
        $email = self::normalize($email);
        $this->checkRateLimits($email, $request->ip());

        // Lookup, token and mail run after the response, so a known address answers exactly like
        // (and as fast as) an unknown one – no user enumeration, not even by timing
        defer(fn () => $this->issue($email));
    }

    /** Addresses are compared trimmed and case-insensitive (Postgres compares case-sensitive) */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function issue(string $email): void
    {
        $user = User::whereRaw('lower(email) = ?', [$email])->where('is_active', true)->orderBy('id')->first();
        if (! $user) {
            return;
        }

        $rawToken = Str::random(64);
        $tokenHash = hash('sha256', $rawToken);
        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        LoginToken::create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'code_hash' => self::codeHash($code),
            'expires_at' => now()->addMinutes(config('magic-link.ttl_minutes', 15)),
            'created_at' => now(),
        ]);

        // A mail error must not answer differently than an unknown address (enumeration)
        try {
            $user->notify(new MagicLinkNotification($rawToken, $code));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function consumeToken(string $rawToken, Request $request): User
    {
        // Not even the format: refused without a database query (and without a log entry)
        if (! preg_match('/^'.self::TOKEN_PATTERN.'$/', $rawToken)) {
            throw ValidationException::withMessages([
                'token' => ['Dieser Link ist ungültig oder abgelaufen. Bitte fordere einen neuen Link an.'],
            ]);
        }

        $tokenHash = hash('sha256', $rawToken);

        $loginToken = LoginToken::where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $loginToken) {
            $this->failed('Link ungültig, abgelaufen oder schon benutzt');
        }

        return $this->login($loginToken, $request, fn () => $this->failed('Link ungültig, abgelaufen oder schon benutzt'));
    }

    /**
     * The code from the mail, typed into the app – for the app on the iPhone home screen, which a link from
     * the mail never opens. Only together with the address; wrong codes count against the token and against
     * address and IP, so the 10^6 possibilities can't be tried out.
     */
    public function consumeCode(string $email, string $code, Request $request): User
    {
        $email = self::normalize($email);
        $code = preg_replace('/\D/', '', $code) ?? '';
        // Not a code at all: refused without a database query
        if (strlen($code) !== self::CODE_LENGTH || $email === '') {
            throw ValidationException::withMessages(['code' => [self::CODE_INVALID]]);
        }

        $keys = ['magic-code:email:'.hash('sha256', $email) => 10, 'magic-code:ip:'.($request->ip() ?? 'unknown') => 30];
        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                app(ActivityRecorder::class)->record('login_throttled', null, null, 'Zu viele Code-Eingaben');
                throw ValidationException::withMessages(['code' => ['Zu viele Versuche. Bitte warte etwas oder nutze den Link aus der Mail.']]);
            }
        }
        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, 3600);
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->where('is_active', true)->orderBy('id')->first();
        $open = $user ? LoginToken::where('user_id', $user->id)->whereNotNull('code_hash')->whereNull('used_at')
            ->where('expires_at', '>', now())->where('code_attempts', '<', self::CODE_ATTEMPTS)->get() : collect();
        $hash = self::codeHash($code);
        $loginToken = $open->first(fn (LoginToken $t) => hash_equals((string) $t->code_hash, $hash));

        if (! $loginToken) {
            // Every wrong code uses up one try of each open code of this Kamerad
            LoginToken::whereKey($open->pluck('id')->all())->increment('code_attempts');
            $this->failed('Code falsch oder abgelaufen', 'code', self::CODE_INVALID);
        }

        return $this->login($loginToken, $request, fn () => $this->failed('Code falsch oder abgelaufen', 'code', self::CODE_INVALID));
    }

    /** @param  callable(): never  $taken  when the token was claimed in the meantime */
    private function login(LoginToken $loginToken, Request $request, callable $taken): User
    {
        // Claimed atomically: of two simultaneous clicks only one logs in
        $claimed = LoginToken::whereKey($loginToken->id)->whereNull('used_at')->update([
            'used_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        if ($claimed === 0) {
            $taken();
        }

        /** @var User $user */
        $user = $loginToken->user;

        if (! $user->is_active || ! $user->hasLogin()) {
            app(ActivityRecorder::class)->record('login_failed', User::class, $user->id, $user->name.' – Konto deaktiviert oder ohne Login', userId: $user->id);
            throw ValidationException::withMessages([
                'token' => ['Dein Konto wurde deaktiviert. Bitte wende dich an den Administrator.'],
            ]);
        }

        // A login ends every other open link of the Kamerad (older login links, an Einladung)
        LoginToken::where('user_id', $user->id)->whereNull('used_at')->delete();

        // The very first login (e.g. from an Einladung) leads to the Willkommen page
        if ($user->last_login_at === null) {
            session()->put('first_login', true);
        }
        $user->update(['last_login_at' => now()]);
        app(ActivityRecorder::class)->record('login', User::class, $user->id, $user->name, userId: $user->id);

        Auth::login($user, remember: true);

        return $user;
    }

    /** Codes are short, so they are stored as HMAC with the app key: a copy of the table alone doesn't reveal them */
    private static function codeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /** A login link or code that doesn't work: logged for the admin "Analyse" (with the IP, no address), then refused */
    private function failed(string $reason, string $field = 'token', string $message = 'Dieser Link ist ungültig oder abgelaufen. Bitte fordere einen neuen Link an.'): never
    {
        app(ActivityRecorder::class)->record('login_failed', null, null, $reason);
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function checkRateLimits(string $email, ?string $ip): void
    {
        $emailKey = 'magic-link:email:'.hash('sha256', $email);
        $ipKey = 'magic-link:ip:'.($ip ?? 'unknown');

        $emailLimit = config('magic-link.rate_limit_per_email', 5);
        $ipLimit = config('magic-link.rate_limit_per_ip', 20);

        if (RateLimiter::tooManyAttempts($emailKey, $emailLimit)) {
            app(ActivityRecorder::class)->record('login_throttled', null, null, 'Zu viele Login-Links für eine E-Mail-Adresse');
            $seconds = RateLimiter::availableIn($emailKey);
            throw ValidationException::withMessages([
                'email' => ["Zu viele Anfragen. Bitte warte {$seconds} Sekunden."],
            ]);
        }

        if (RateLimiter::tooManyAttempts($ipKey, $ipLimit)) {
            app(ActivityRecorder::class)->record('login_throttled', null, null, 'Zu viele Login-Links von einer IP-Adresse');
            throw ValidationException::withMessages([
                'email' => ['Zu viele Anfragen von dieser IP-Adresse. Bitte versuche es später erneut.'],
            ]);
        }

        RateLimiter::hit($emailKey, 3600);
        RateLimiter::hit($ipKey, 3600);
    }
}
