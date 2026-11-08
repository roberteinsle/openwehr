<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Wehr reaches openwehr under its own domain (APP_URL) and under more addresses (APP_ALIAS_URLS, e.g.
 * musterstadt.openwehr.de). Page requests to an alias go permanently to the same path under APP_URL, so
 * login, push and the home-screen app live under one address; /sw.js there is a worker that removes itself
 * (resources/js/sw-alias.js), because browsers never follow a redirect for it. Everything else passes: POSTs (webhooks,
 * Livewire) and hosts that are neither, such as the container's own healthcheck.
 */
class RedirectAliasHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $target = rtrim((string) config('app.url'), '/');
        $host = strtolower($request->getHost());

        if ($target !== '' && in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && in_array($host, self::aliasHosts(), true) && $host !== strtolower((string) parse_url($target, PHP_URL_HOST))) {
            // A service worker script may not be redirected (the browser would keep the old worker for good):
            // the alias gets one that removes itself, so installed apps follow the redirect afterwards
            if ($request->path() === 'sw.js') {
                return response((string) file_get_contents(resource_path('js/sw-alias.js')), 200, [
                    'Content-Type' => 'application/javascript; charset=utf-8',
                    'Cache-Control' => 'no-cache',
                ]);
            }

            return redirect()->away($target.$request->getRequestUri(), 301);
        }

        return $next($request);
    }

    /** @return list<string> the hosts of APP_ALIAS_URLS */
    public static function aliasHosts(): array
    {
        return array_values(array_filter(array_map(
            fn (string $url) => strtolower((string) (parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST) ?? '')),
            (array) config('app.alias_urls', []),
        )));
    }
}
