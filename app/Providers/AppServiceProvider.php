<?php

namespace App\Providers;

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\AnnualReportRegistry;
use App\Services\BadgeRegistry;
use App\Services\CredentialRegistry;
use App\Services\DashboardRegistry;
use App\Services\DriverRegistry;
use App\Services\HoursRegistry;
use App\Services\InventoryRegistry;
use App\Services\ModuleRegistry;
use App\Services\OrganizationSettings;
use App\Services\PersonnelFileRegistry;
use App\Services\PushService;
use App\Services\ScanRegistry;
use App\Services\VehicleBlockRegistry;
use App\Services\VehicleBookingRegistry;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(OrganizationSettings::class);
        $this->app->singleton(ScanRegistry::class);
        $this->app->singleton(InventoryRegistry::class);
        $this->app->singleton(DashboardRegistry::class);
        $this->app->singleton(AnnualReportRegistry::class);
        $this->app->singleton(ActivityRecorder::class);
        $this->app->singleton(PersonnelFileRegistry::class);
        $this->app->singleton(VehicleBlockRegistry::class);
        $this->app->singleton(VehicleBookingRegistry::class);
        $this->app->singleton(PushService::class);
        $this->app->singleton(CredentialRegistry::class);
        $this->app->singleton(HoursRegistry::class);
        $this->app->singleton(DriverRegistry::class);
        $this->app->singleton(BadgeRegistry::class);
    }

    public function boot(): void
    {
        // Links in mails (magic links, Einladungen) take their host from APP_URL, never from a
        // forwarded Host header – otherwise a spoofed X-Forwarded-Host could send a login link elsewhere
        if (filled($url = config('app.url'))) {
            URL::forceRootUrl($url);
            if (str_starts_with($url, 'https://')) {
                URL::forceScheme('https');
            }
        }

        // Livewire actions (/livewire/update) run the same checks as the page: still active, module
        // still on and still allowed – a Kamerad who left can't keep acting from an open tab
        Livewire::addPersistentMiddleware([EnsureUserIsActive::class, EnsureModuleAccess::class]);

        // Every change of a record goes to the admin "Logs"
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", fn (string $name, array $payload) => app(ActivityRecorder::class)->handle($name, $payload));
        }

        // Every entry under the bell also goes as push to the Kamerad's devices
        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            if ($event->channel === 'database' && $event->notifiable instanceof User && $event->response instanceof DatabaseNotification) {
                try {
                    $data = $event->response->data;
                    app(PushService::class)->toUser($event->notifiable, (string) ($data['title'] ?? config('app.name')), (string) ($data['body'] ?? ''), $data['url'] ?? null);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        FilamentView::registerRenderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.brand-buttons'));
        // A Kamerad's module panels (e.g. Kleiderwart) also show the way back for an admin viewing as them
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_START, fn () => view('partials.impersonation-banner'));

        // Share org settings with every view under the app layout
        View::composer(['components.layouts.app', 'filament.*'], function ($view) {
            $org = app(OrganizationSettings::class);
            $view->with('orgName', $org->shortName());
            $view->with('orgLogoUrl', $org->logoUrl() ?? asset('images/openwehr-logo.svg'));
        });
    }
}
