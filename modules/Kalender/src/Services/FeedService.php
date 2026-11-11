<?php

namespace Modules\Kalender\src\Services;

use App\Models\Guardian;
use App\Models\User;
use App\Services\MagicLinkService;
use App\Services\ParentAccess;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Modules\Kalender\src\Models\FeedToken;

/** The personal subscription link (read-only iCal feed of the events meant for the Kamerad, or for the children of a parent) */
class FeedService
{
    public function url(User $user): string
    {
        $token = FeedToken::where('user_id', $user->id)->first();
        $raw = $token ? Crypt::decryptString($token->token_encrypted) : $this->regenerate($user);

        return route('kalender.feed', ['token' => $raw]);
    }

    /** A new link; the old one stops working at once */
    public function regenerate(User $user): string
    {
        $raw = Str::random(48);

        FeedToken::updateOrCreate(['user_id' => $user->id], [
            'token_hash' => hash('sha256', $raw),
            'token_encrypted' => Crypt::encryptString($raw),
        ]);

        return $raw;
    }

    public function userFor(string $raw): ?User
    {
        return FeedToken::where('token_hash', hash('sha256', $raw))->whereNotNull('user_id')->first()?->user;
    }

    /**
     * Eltern-Bereich: one link per parent address with the Termine of all their children. The address is
     * stored only as a hash, so the token row reveals nobody; the children are looked up on every call.
     */
    public function parentUrl(string $email): string
    {
        $token = FeedToken::where('parent_hash', self::parentHash($email))->first();
        $raw = $token ? Crypt::decryptString($token->token_encrypted) : $this->regenerateParent($email);

        return route('kalender.feed', ['token' => $raw]);
    }

    public function regenerateParent(string $email): string
    {
        $raw = Str::random(48);

        FeedToken::updateOrCreate(['parent_hash' => self::parentHash($email)], [
            'user_id' => null,
            'token_hash' => hash('sha256', $raw),
            'token_encrypted' => Crypt::encryptString($raw),
        ]);

        return $raw;
    }

    /**
     * The children behind a parent link (active, with Kalender access); null if it is no parent link
     *
     * @return EloquentCollection<int, User>|null
     */
    public function childrenFor(string $raw): ?EloquentCollection
    {
        $token = FeedToken::where('token_hash', hash('sha256', $raw))->whereNotNull('parent_hash')->first();
        if (! $token) {
            return null;
        }

        // The hash can't be reversed: the guardians whose address hashes to it are found among all guardians
        $emails = Guardian::query()->pluck('email')->map(fn ($e) => MagicLinkService::normalize((string) $e))->unique()
            ->filter(fn (string $e) => hash_equals((string) $token->parent_hash, self::parentHash($e)));

        return $emails->isEmpty() ? new EloquentCollection
            : ParentAccess::childrenOf($emails->first())->filter(fn (User $child) => $child->canUseModule('kalender'))->values();
    }

    /** HMAC with the app key: the hash alone doesn't reveal the address */
    private static function parentHash(string $email): string
    {
        return hash_hmac('sha256', MagicLinkService::normalize($email), (string) config('app.key'));
    }
}
