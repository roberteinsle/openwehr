<?php

namespace App\Http\Middleware;

use App\Services\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts page requests per hour and area (module key, "admin" or "kern") with response time and
 * server errors, for the admin "Analyse". No user, no URL, no IP – only the sums.
 * Livewire updates count for the area of the page they come from.
 */
class RecordRequestMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);

        try {
            $this->record($request, $response->getStatusCode(), (int) ((hrtime(true) - $start) / 1_000_000));
        } catch (Throwable) {
            // figures are nice to have; a request never fails because of them
        }

        return $response;
    }

    private function record(Request $request, int $status, int $ms): void
    {
        if (app()->runningUnitTests() && ! config('app.record_request_metrics')) {
            return;
        }
        $area = self::area($request);
        if ($area === null) {
            return;
        }
        $hour = now()->startOfHour();
        DB::statement(
            'INSERT INTO request_metrics (hour, area, count, server_errors, total_ms, max_ms) VALUES (?, ?, 1, ?, ?, ?)
             ON CONFLICT (hour, area) DO UPDATE SET count = request_metrics.count + 1, server_errors = request_metrics.server_errors + EXCLUDED.server_errors,
             total_ms = request_metrics.total_ms + EXCLUDED.total_ms, max_ms = GREATEST(request_metrics.max_ms, EXCLUDED.max_ms)',
            [$hour, $area, $status >= 500 ? 1 : 0, $ms, $ms],
        );
    }

    /** The module the request belongs to, "admin", "kern" – or null for things not worth counting (health check, files) */
    public static function area(Request $request): ?string
    {
        $name = (string) $request->route()?->getName();
        if ($name === 'livewire.update') {
            $referer = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: '/';
            $name = rescue(fn () => app('router')->getRoutes()->match(Request::create($referer))->getName(), '', false) ?? '';
        }
        if (str_starts_with($name, 'filament.')) {
            return 'admin';
        }
        if ($request->is('up') || $name === '' && ! $request->route()) {
            return null;
        }
        $prefix = strtok($name, '.') ?: '';

        return app(ModuleRegistry::class)->has($prefix) ? $prefix : 'kern';
    }
}
