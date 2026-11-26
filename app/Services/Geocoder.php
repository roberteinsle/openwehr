<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Address → coordinates via the Google Geocoding API (key under Admin → Einstellungen → Dienste) */
class Geocoder
{
    public function __construct(private readonly ServiceSettings $services) {}

    public function available(): bool
    {
        return filled($this->services->googleMapsKey());
    }

    /** @return array{lat: float, lng: float}|null null without key, without result or on any error */
    public function locate(?string $address): ?array
    {
        $key = $this->services->googleMapsKey();
        if (blank($address) || blank($key)) {
            return null;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'region' => 'de',
                'language' => 'de',
                'key' => $key,
            ]);
            $location = $response->json('results.0.geometry.location');

            return $response->json('status') === 'OK' && isset($location['lat'], $location['lng'])
                ? ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']]
                : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Address → coordinates via OpenStreetMap's Nominatim, for when no Google key is set (the DIVERA
     * alarm). Sends the address to the OpenStreetMap Foundation; one request per alarm, which is well
     * within Nominatim's usage policy (at most one request a second, a User-Agent naming the app).
     *
     * @return array{lat: float, lng: float}|null
     */
    public function locateOpenStreetMap(?string $address): ?array
    {
        if (blank($address)) {
            return null;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->withUserAgent('openwehr (self-hosted fire brigade app)')
                ->get('https://nominatim.openstreetmap.org/search', ['q' => $address, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'de', 'accept-language' => 'de']);
            $hit = $response->json('0');

            return $response->successful() && isset($hit['lat'], $hit['lon']) && is_numeric($hit['lat']) && is_numeric($hit['lon'])
                ? ['lat' => (float) $hit['lat'], 'lng' => (float) $hit['lon']]
                : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
