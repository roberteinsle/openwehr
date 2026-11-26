<?php

namespace App\Support;

/**
 * Access to the read-only monitors at the station (Wachen-Monitor, Fahrzeugbelegung, Termin-Monitor):
 * admins, or the screen with the secret DISPLAY_TOKEN in the URL. Everyone else gets a 404, so the
 * pages don't reveal that they exist.
 */
final class DisplayToken
{
    public static function allows(?string $key): bool
    {
        $token = (string) config('monitor.token');

        return (bool) auth()->user()?->is_admin || ($token !== '' && $key !== null && hash_equals($token, $key));
    }

    /** The link for the station PC, or null while no DISPLAY_TOKEN is set */
    public static function url(string $route): ?string
    {
        $token = (string) config('monitor.token');

        return $token !== '' ? route($route, ['key' => $token]) : null;
    }
}
