<?php

namespace App\Services;

use App\Enums\MemberStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Nur Elternteil": a Kamerad account that was made only so a parent can sign in, though the parent is
 * no member. Converted, the parent signs in with the same address through the Eltern-Login (guard
 * "guardian", the address stored at the child): no Dienstausweis, Stunden, Kamerad modules or member
 * lists. The account is deleted; if data hangs on it, it stays as "Ausgetreten" without address.
 */
class ParentOnlyAccount
{
    /** Whether the account can become a pure parent access: its address is a guardian's of an active child */
    public static function candidate(User $user): bool
    {
        return $user->hasLogin() && ! $user->is_admin
            && ParentAccess::childrenOf((string) $user->email)->reject(fn (User $child) => $child->id === $user->id)->isNotEmpty();
    }

    /** @return bool true if the account was deleted, false if it stays as "Ausgetreten" without address */
    public function convert(User $user, User $by): bool
    {
        if (! self::candidate($user)) {
            throw new RuntimeException('Diese Adresse ist bei keinem aktiven Kind als Erziehungsberechtigte(r) eingetragen.');
        }

        $label = $user->name.' → Eltern-Zugang';
        $id = $user->id;

        try {
            $deleted = DB::transaction(function () use ($user) {
                $user->delete();

                return true;
            });
        } catch (QueryException $e) {
            if (! in_array($e->getCode(), ['23001', '23503'], true)) {
                throw $e;
            }
            $user->forceFill(['status' => MemberStatus::Left, 'email' => null])->save();
            $deleted = false;
        }

        app(ActivityRecorder::class)->record('parent_only', User::class, $id, $label, ['deleted' => $deleted], userId: $by->id);

        return $deleted;
    }
}
