<?php

namespace Modules\Uebungsdienste;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Uebungsdienste\src\Console\Commands\SyncCalendar;
use Modules\Uebungsdienste\src\Livewire\Planner;
use Modules\Uebungsdienste\src\Livewire\Planners;
use Modules\Uebungsdienste\src\Livewire\ServiceForm;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\ServicePlan;

/**
 * Übungsdienstplaner: who runs which Übungsdienst with which topic. Meant for the Vorstand and the
 * Übungsdienstplaner of the Abteilungen, so members don't just come to the "exciting" Dienste.
 */
class UebungsdiensteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ServicePlan::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'uebungsdienste');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SyncCalendar::class]);

        // Dienste not in the Kalender yet (e.g. the Kalender was switched on later)
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(SyncCalendar::class)->hourly()->withoutOverlapping()->appendOutputTo($output);
        });

        Livewire::component('uebungsdienste.planner', Planner::class);
        Livewire::component('uebungsdienste.service-form', ServiceForm::class);

        Livewire::component('uebungsdienste.planners', Planners::class);

        // Create, change, delete Dienste: admins and Übungsdienstplaner (within their Abteilungen, see ServicePlan)
        Gate::define('plan-uebungsdienste', fn (User $user) => ServicePlan::canPlan($user));

        app(ModuleRegistry::class)->register(
            'uebungsdienste',
            'Übungsdienstplaner',
            'Wer welchen Übungsdienst mit welchem Thema übernimmt – für Vorstand und Übungsdienstplaner der Abteilungen, mit Tausch und Fahrzeugbuchung',
            roles: [ServicePlan::ROLE],
            provider: self::class,
            route: 'uebungsdienste.index',
            // Vorstand incl. its sub-functions, and admins; anyone else only after a confirmation
            audience: fn (User $user) => $user->isVorstand() || $user->hasModuleRole('uebungsdienste', ServicePlan::ROLE),
            audienceLabel: 'Vorstand',
        );

        // Start page: the next Übungsdienst. The Vorstand (module access) sees topic and who is responsible,
        // every other Kamerad of the target group date, time and place – like the Kalender, never the topic
        app(DashboardRegistry::class)->register('uebungsdienste', 'next', function (User $user) {
            $planner = $user->canUseModule('uebungsdienste');
            $next = $planner ? app(ServicePlan::class)->next($user) : app(ServicePlan::class)->nextFor($user);

            // In several Abteilungen (or seeing the whole plan): for whom the Dienst is
            return $next ? view('uebungsdienste::cards.next', ['service' => $next, 'planner' => $planner,
                'withAudience' => $planner || $user->groups()->count() > 1])->render() : null;
        }, everyone: true);

        // Jahresbericht: how many Übungsdienste were planned (the topics stay in the plan)
        app(AnnualReportRegistry::class)->register('uebungsdienste', 'Übungsdienste', function (int $year) {
            $count = Service::whereYear('date', $year)->count();

            return $count === 0 ? null : '<div class="ar-grid"><div class="ar-tile"><span>Übungsdienste geplant</span><strong>'.$count.'</strong></div>'
                .'<div class="ar-tile"><span>davon stattgefunden</span><strong>'.Service::whereYear('date', $year)->whereDate('date', '<', ServicePlan::today())->count().'</strong></div></div>';
        }, 35);

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('uebungsdienste', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Übungsdienste', 'uebungsdienste.index', ['uebungsdienste.edit', 'uebungsdienste.create']),
                $u->can('plan-uebungsdienste') ? ModuleNav::tab('Orte & Kleidung', 'uebungsdienste.places') : null,
                $u->is_admin ? ModuleNav::tab('Planer & Abteilungen', 'uebungsdienste.planners') : null,
            ],
        ]);
    }
}
