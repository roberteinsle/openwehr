<?php

namespace App\Support;

use App\Models\Setting;

/**
 * How long logs are kept, set by admins under Analyse: technical logs (errors, request figures,
 * heartbeats) short, the audit log (who changed what) and the mail log longer when needed.
 */
class Retention
{
    public const KEY = 'analytics.retention';

    public const DEFAULTS = ['technical' => 90, 'audit' => 180, 'mail' => 180];

    public const LIMITS = ['technical' => [7, 365], 'audit' => [30, 3650], 'mail' => [30, 3650]];

    /** @return array{technical: int, audit: int, mail: int} days */
    public static function days(): array
    {
        $stored = json_decode((string) Setting::get(self::KEY, ''), true) ?: [];

        return array_map('intval', array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS)));
    }

    public static function of(string $kind): int
    {
        return self::days()[$kind] ?? self::DEFAULTS[$kind];
    }

    /** @param  array<string, int|string>  $days */
    public static function save(array $days): void
    {
        $clean = [];
        foreach (self::LIMITS as $kind => [$min, $max]) {
            $clean[$kind] = max($min, min($max, (int) ($days[$kind] ?? self::DEFAULTS[$kind])));
        }
        Setting::set(self::KEY, json_encode($clean));
    }
}
