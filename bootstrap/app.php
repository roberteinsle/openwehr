<?php

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\RecordRequestMetrics;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ServeLinkPreview;
use App\Services\ErrorRecorder;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        // No search engine or AI crawler may take up anything of the app
        $middleware->append(NoIndex::class);
        $middleware->append(SecurityHeaders::class);
        // Page requests per hour and area, with response times and server errors (admin "Analyse")
        $middleware->append(RecordRequestMetrics::class);
        $middleware->redirectGuestsTo('/anmelden');
        // Before "auth" redirects a messenger's link preview to the login page
        $middleware->appendToGroup('web', ServeLinkPreview::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ServeLinkPreview::class);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'module' => EnsureModuleAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every reported error also goes to the admin "Analyse" (grouped, admins told once a day per place)
        $exceptions->report(function (Throwable $e) {
            app(ErrorRecorder::class)->record($e);
        });
    })->create();
