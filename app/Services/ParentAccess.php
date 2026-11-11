<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Who is using the Eltern-Bereich: an Erziehungsberechtigte(r) signed in with the guard "guardian", or a
 * Kamerad whose own address is stored as guardian of a child (a parent who is in the Wehr too). The children
 * are the active Kameraden with a guardian row of that address – siblings are joined by the address.
 */
class ParentAccess
{
    /** The address of the parent using the app right now, lowercase; null if nobody or no parent */
    public function email(): ?string
    {
        $guardian = Auth::guard('guardian')->user();
        if ($guardian instanceof Guardian) {
            return MagicLinkService::normalize((string) $guardian->email);
        }
        $user = Auth::guard('web')->user();

        return $user instanceof User && $user->hasLogin() ? MagicLinkService::normalize((string) $user->email) : null;
    }

    /** The name shown for the parent: the guardian row, or the Kamerad */
    public function name(): ?string
    {
        $guardian = Auth::guard('guardian')->user();
        if ($guardian instanceof Guardian) {
            return $guardian->name;
        }

        return Auth::guard('web')->user()?->name;
    }

    /** @return Collection<int, User> the active children of the current parent, by name */
    public function children(): Collection
    {
        $email = $this->email();

        return $email === null ? new Collection : self::childrenOf($email);
    }

    /** @return Collection<int, User> */
    public static function childrenOf(string $email): Collection
    {
        return User::where('is_active', true)
            ->whereHas('guardians', fn ($q) => $q->whereRaw('lower(email) = ?', [MagicLinkService::normalize($email)]))
            ->orderBy('name')->get();
    }

    public function hasChildren(): bool
    {
        return $this->children()->isNotEmpty();
    }

    /** The guardian row of the current parent for this child (the answer and the log name it) */
    public function guardianFor(User|int $child): ?Guardian
    {
        $email = $this->email();
        $id = $child instanceof User ? $child->id : $child;

        return $email === null ? null : Guardian::where('user_id', $id)->whereRaw('lower(email) = ?', [$email])
            ->whereHas('user', fn ($q) => $q->where('is_active', true))->first();
    }

    /** The child, if the current parent may act for it; null otherwise */
    public function child(int $userId): ?User
    {
        return $this->guardianFor($userId)?->user;
    }

    /** Whether someone uses the app as a parent only (signed in as guardian, without a Kamerad account) */
    public function guardianOnly(): bool
    {
        return Auth::guard('guardian')->check() && ! Auth::guard('web')->check();
    }

    /**
     * The member a "Meine …" page shows: the signed-in Kamerad, or – with ?kind= – a child of the parent.
     * Modules use it so the same component serves the Kamerad and the Eltern-Bereich.
     */
    public function memberFor(?int $childId): ?User
    {
        if ($childId !== null) {
            return $this->child($childId);
        }
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }
}
