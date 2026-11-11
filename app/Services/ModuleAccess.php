<?php

namespace App\Services;

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who may use a module, besides "module active" and admins (User::canUseModule):
 * - an explicit grant (module_user, with the role) always counts;
 * - otherwise "automatisch freischalten" (modules.auto_assign) – for the module's audience, unless an admin
 *   took the module away from this Kamerad (module_blocks) or all of their Abteilungen keep it from their
 *   members (groups.excluded_modules, e.g. the Jugendfeuerwehr without Schlüsselverwaltung).
 */
class ModuleAccess
{
    public static function autoAssign(string $key): bool
    {
        return (bool) Cache::remember("module_auto_assign_{$key}", 60, fn () => Module::where('key', $key)->value('auto_assign'));
    }

    public static function granted(User $user, string $key): bool
    {
        return $user->moduleAccess()->where('module', $key)->exists();
    }

    public static function blocked(User $user, string $key): bool
    {
        return DB::table('module_blocks')->where('user_id', $user->id)->where('module', $key)->exists();
    }

    /** Every Abteilung of the Kamerad keeps the module from its members (Kameraden without Abteilung: never) */
    public static function excludedByGroups(User $user, string $key): bool
    {
        $groups = $user->relationLoaded('groups') ? $user->groups : $user->groups()->get();

        return $groups->isNotEmpty() && $groups->every(fn ($g) => in_array($key, $g->excluded_modules ?? [], true));
    }

    /** What "automatisch freischalten" gives this Kamerad */
    public static function autoGranted(User $user, string $key): bool
    {
        return self::autoAssign($key) && app(ModuleRegistry::class)->isMeantFor($key, $user)
            && ! self::blocked($user, $key) && ! self::excludedByGroups($user, $key);
    }

    /** Access regardless of whether the module is switched on and of the admin flag */
    public static function effective(User $user, string $key): bool
    {
        return self::granted($user, $key) || self::autoGranted($user, $key);
    }

    /**
     * Switch a module on or off for one Kamerad. Off removes the grant and – for an automatically given
     * module – blocks it, so "automatisch freischalten" no longer gives it back.
     */
    public static function set(User|int $user, string $key, bool $enabled, ?string $role = null, bool $keepRole = false): void
    {
        $id = $user instanceof User ? $user->id : $user;
        if ($enabled) {
            $row = DB::table('module_user')->where('user_id', $id)->where('module', $key);
            if ($row->exists()) {
                if (! $keepRole || $role !== null) {
                    $row->update(['role' => $role]);
                }
            } else {
                DB::table('module_user')->insert(['user_id' => $id, 'module' => $key, 'role' => $role]);
            }
            DB::table('module_blocks')->where('user_id', $id)->where('module', $key)->delete();

            return;
        }

        DB::table('module_user')->where('user_id', $id)->where('module', $key)->delete();
        if (self::autoAssign($key)) {
            DB::table('module_blocks')->insertOrIgnore(['user_id' => $id, 'module' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
