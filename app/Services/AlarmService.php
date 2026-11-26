<?php

namespace App\Services;

use App\Models\Alarm;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The DIVERA webhook in the core (Admin → DIVERA-Webhook): DIVERA 24/7 sends every alarm as
 * "Webhook – POST application/json" to /webhooks/alarm/{key}. The alarm is stored, located (DIVERA's
 * coordinates, else the address via Google or OpenStreetMap), shown on the Wachen-Monitor and handed
 * to the modules (AlarmRegistry, e.g. the Einsatzbericht's draft). DIVERA does not sign requests; the
 * secret key in the address is the protection.
 */
class AlarmService
{
    public const SETTINGS = 'alarm.divera';

    public const KEY_SETTING = 'alarm.divera_key';

    /** Minutes an alarm stays on the Wachen-Monitor after its last update; 0 = not at all */
    public const SHOW_MINUTES = [0, 15, 30, 60, 120, 240];

    public const DEFAULTS = ['enabled' => false, 'show_minutes' => 60, 'osm' => true];

    public function __construct(private readonly Geocoder $geocoder, private readonly AlarmRegistry $registry) {}

    // ─── settings ────────────────────────────────────────────────────────────

    /** @return array{enabled: bool, show_minutes: int, osm: bool} */
    public static function settings(): array
    {
        $stored = json_decode((string) Setting::get(self::SETTINGS, ''), true);
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? self::DEFAULTS['enabled']),
            'show_minutes' => in_array((int) ($stored['show_minutes'] ?? -1), self::SHOW_MINUTES, true) ? (int) $stored['show_minutes'] : self::DEFAULTS['show_minutes'],
            'osm' => (bool) ($stored['osm'] ?? self::DEFAULTS['osm']),
        ];
    }

    public static function saveSettings(bool $enabled, int $showMinutes, bool $osm): void
    {
        if (! in_array($showMinutes, self::SHOW_MINUTES, true)) {
            throw new \InvalidArgumentException('Unknown duration');
        }
        if ($enabled && self::key() === null) {
            self::regenerateKey();
        }
        Setting::set(self::SETTINGS, json_encode(['enabled' => $enabled, 'show_minutes' => $showMinutes, 'osm' => $osm]));
    }

    public static function enabled(): bool
    {
        return self::settings()['enabled'] && self::key() !== null;
    }

    /** The secret part of the address, stored encrypted */
    public static function key(): ?string
    {
        $stored = Setting::get(self::KEY_SETTING);

        try {
            return $stored ? Crypt::decryptString($stored) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function regenerateKey(): string
    {
        $key = Str::random(48);
        Setting::set(self::KEY_SETTING, Crypt::encryptString($key));

        return $key;
    }

    public static function url(): ?string
    {
        return self::key() ? route('webhooks.alarm', ['key' => self::key()]) : null;
    }

    // ─── alarms ──────────────────────────────────────────────────────────────

    /**
     * Stores an alarm from DIVERA's JSON (the full object, "Textinformationen", "Adresse und
     * Koordinaten" … – whatever fields come). The same DIVERA id again updates that alarm; "closed"
     * ends it. Locating and the modules come afterwards (handle()).
     *
     * @return array{0: Alarm, 1: bool} the alarm and whether it is new
     */
    public function receive(array $payload, string $source = 'divera'): array
    {
        $fields = self::extract($payload);
        $source = ($payload['openwehr_test'] ?? false) === true ? 'test' : $source;

        $alarm = $fields['external_id'] !== null
            ? Alarm::where('source', $source)->where('external_id', $fields['external_id'])->where('received_at', '>=', now()->subDay())->first()
            : null;
        $isNew = $alarm === null;

        $values = array_filter([
            'keyword' => $fields['keyword'], 'message' => $fields['message'], 'address' => $fields['address'],
            'latitude' => $fields['latitude'], 'longitude' => $fields['longitude'],
            'located_by' => $fields['latitude'] !== null ? 'divera' : null,
        ], fn ($v) => $v !== null);

        if ($isNew) {
            $alarm = Alarm::create([...$values, 'source' => $source, 'external_id' => $fields['external_id'], 'priority' => $fields['priority'],
                'payload' => $payload, 'received_at' => now()]);
        } else {
            // A new address without coordinates: the old position no longer fits
            if (isset($values['address']) && $values['address'] !== $alarm->address && $fields['latitude'] === null) {
                $values += ['latitude' => null, 'longitude' => null, 'located_by' => null];
            }
            $alarm->fill([...$values, 'priority' => $fields['priority'] || $alarm->priority, 'payload' => $payload]);
            $alarm->updated_at = now();
            $alarm->save();
        }

        if ($fields['closed'] && $alarm->ended_at === null) {
            $alarm->update(['ended_at' => now(), 'ended_by' => 'DIVERA']);
        }

        return [$alarm, $isNew];
    }

    /** After the response: coordinates from the address if DIVERA sent none, then the modules */
    public function handle(Alarm $alarm, bool $isNew): void
    {
        try {
            $this->locate($alarm);
        } catch (Throwable $e) {
            report($e);
        }
        $this->registry->dispatch($alarm->refresh(), $isNew);
    }

    public function locate(Alarm $alarm): void
    {
        if ($alarm->hasPosition() || blank($alarm->address)) {
            return;
        }
        if ($this->geocoder->available() && ($point = $this->geocoder->locate($alarm->address))) {
            $alarm->update(['latitude' => $point['lat'], 'longitude' => $point['lng'], 'located_by' => 'google']);
        } elseif (self::settings()['osm'] && ($point = $this->geocoder->locateOpenStreetMap($alarm->address))) {
            $alarm->update(['latitude' => $point['lat'], 'longitude' => $point['lng'], 'located_by' => 'osm']);
        }
    }

    /** The alarm the Wachen-Monitor shows now: the newest one not ended and updated within show_minutes */
    public static function current(): ?Alarm
    {
        return self::running()->first();
    }

    /** @return Collection<int, Alarm> newest first */
    public static function running()
    {
        $minutes = self::settings()['show_minutes'];
        if ($minutes === 0) {
            return Alarm::query()->whereRaw('false')->get();
        }

        return Alarm::whereNull('ended_at')->where('updated_at', '>=', now()->subMinutes($minutes))->latest('received_at')->latest('id')->get();
    }

    /** Short fingerprint for the monitor's quick check: changes when an alarm comes, changes or ends */
    public static function state(): string
    {
        $alarm = self::current();

        return $alarm ? $alarm->id.'-'.$alarm->updated_at?->getTimestamp() : '';
    }

    public function end(Alarm $alarm, string $by): void
    {
        if ($alarm->ended_at === null) {
            $alarm->update(['ended_at' => now(), 'ended_by' => mb_substr($by, 0, 150)]);
        }
    }

    /**
     * A test alarm from the admin, built like DIVERA's full object. Either straight through the same
     * processing, or as a real POST to the public address (checks proxy and key, too).
     *
     * @param  array{keyword: string, message?: ?string, address?: ?string, latitude?: ?float, longitude?: ?float, priority?: bool}  $fields
     */
    public function test(array $fields, bool $viaUrl = false): Alarm
    {
        if (! self::enabled()) {
            throw new RuntimeException('Der Webhook ist ausgeschaltet. Erst einschalten und speichern.');
        }

        $payload = array_filter([
            'id' => 'test-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)),
            'title' => $fields['keyword'],
            'text' => $fields['message'] ?? null,
            'address' => $fields['address'] ?? null,
            'lat' => $fields['latitude'] ?? null,
            'lng' => $fields['longitude'] ?? null,
            'priority' => (bool) ($fields['priority'] ?? false),
            'ts_create' => now()->getTimestamp(),
            'ts_update' => now()->getTimestamp(),
        ], fn ($v) => $v !== null && $v !== '') + ['openwehr_test' => true];

        if ($viaUrl) {
            $before = (int) Alarm::max('id');
            $response = Http::timeout(15)->asJson()->post((string) self::url(), $payload);
            if (! $response->successful()) {
                throw new RuntimeException('Die Webhook-Adresse antwortet mit HTTP '.$response->status().'.');
            }

            return Alarm::where('id', '>', $before)->latest('id')->firstOrFail();
        }

        [$alarm, $isNew] = $this->receive($payload, 'test');
        $this->handle($alarm, $isNew);

        return $alarm->refresh();
    }

    /**
     * The alarm fields from DIVERA's JSON, at the top level or under "data"
     *
     * @return array{external_id: ?string, keyword: ?string, message: ?string, address: ?string, latitude: ?float, longitude: ?float, priority: bool, closed: bool}
     */
    public static function extract(array $payload): array
    {
        $sources = array_values(array_filter([$payload, is_array($payload['data'] ?? null) ? $payload['data'] : null]));
        $value = function (string ...$paths) use ($sources) {
            foreach ($paths as $path) {
                foreach ($sources as $source) {
                    $found = Arr::get($source, $path);
                    if (is_scalar($found) && trim((string) $found) !== '') {
                        return trim((string) $found);
                    }
                }
            }

            return null;
        };
        $coordinate = fn (?string $v, float $max) => $v !== null && is_numeric(str_replace(',', '.', $v)) && abs((float) str_replace(',', '.', $v)) <= $max
            ? (float) str_replace(',', '.', $v) : null;
        $flag = fn (?string $v) => in_array(mb_strtolower((string) $v), ['1', 'true', 'ja', 'yes'], true);

        $lat = $coordinate($value('lat', 'latitude'), 90);
        $lng = $coordinate($value('lng', 'lon', 'longitude'), 180);
        // DIVERA sends 0/0 when it has no position
        if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0)) {
            $lat = $lng = null;
        }

        return [
            'external_id' => ($id = $value('id', 'foreign_id', 'alarm_id')) !== null ? mb_substr($id, 0, 100) : null,
            'keyword' => ($keyword = $value('title', 'keyword', 'stichwort')) !== null ? mb_substr($keyword, 0, 150) : null,
            'message' => ($text = $value('text', 'message', 'meldung')) !== null ? mb_substr($text, 0, 5000) : null,
            'address' => ($address = $value('address', 'adresse')) !== null ? mb_substr($address, 0, 255) : null,
            'latitude' => $lat,
            'longitude' => $lng,
            'priority' => $flag($value('priority')),
            'closed' => $flag($value('closed')),
        ];
    }

    /** "seit 12 Min." style age for lists */
    public static function age(Carbon $at): string
    {
        $minutes = (int) $at->diffInMinutes(now(), true);

        return $minutes < 60 ? $minutes.' Min.' : intdiv($minutes, 60).' Std. '.($minutes % 60).' Min.';
    }
}
