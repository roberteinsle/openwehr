<?php

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureParent;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\RecordRequestMetrics;
use App\Http\Middleware\RedirectAliasHost;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ServeLinkPreview;
use App\Services\ErrorRecorder;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Livewire\Features\SupportFileUploads\MissingFileUploadsTraitException;

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
        // Further addresses of the installation lead to APP_URL (APP_ALIAS_URLS); after the proxies are trusted
        $middleware->append(RedirectAliasHost::class);
        // An Erziehungsberechtigte(r) on a Kamerad page goes to the Eltern-Bereich instead of the login
        $middleware->redirectGuestsTo(fn () => auth('guardian')->check() ? '/eltern' : '/anmelden');
        // Before "auth" redirects a messenger's link preview to the login page
        $middleware->appendToGroup('web', ServeLinkPreview::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ServeLinkPreview::class);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'module' => EnsureModuleAccess::class,
            'parent' => EnsureParent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A crafted "_startUpload" call to a component without uploads – scanners probe the public login form for
        // Livewire upload flaws. Livewire refuses it before anything happens: a bad request, not an error of ours
        $exceptions->dontReport(MissingFileUploadsTraitException::class);
        $exceptions->render(fn (MissingFileUploadsTraitException $e) => response('', 400));

        // Every reported error also goes to the admin "Analyse" (grouped, admins told once a day per place)
        $exceptions->report(function (Throwable $e) {
            app(ErrorRecorder::class)->record($e);
        });
    })->create();
