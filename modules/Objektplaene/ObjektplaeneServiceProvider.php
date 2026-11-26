<?php

namespace Modules\Objektplaene;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Services\SearchRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Objektplaene\src\Console\Commands\SendReviewReminders;
use Modules\Objektplaene\src\Livewire\Library;
use Modules\Objektplaene\src\Livewire\ObjectDetail;
use Modules\Objektplaene\src\Livewire\ObjectForm;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Objektplaene\src\Services\ObjectPlanService;
use Modules\Objektplaene\src\Support\SearchSource as ObjektplaeneSearchSource;

/**
 * Objektpläne & Brandschutzunterlagen: Feuerwehrpläne, Objektpläne and Brandschutzordnungen per
 * object with versions; at an Einsatz the plans of the Einsatzstelle come up by themselves
 * (Einsatzbericht, Lagemeldungen, start page), and they can be kept offline.
 */
class ObjektplaeneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ObjectPlanService::class);
    }

    public function boot(): void
    {
        app(SearchRegistry::class)->register('objektplaene.objekte', 'objektplaene', 'Objektpläne', fn (string $term, User $user) => ObjektplaeneSearchSource::search($term, $user), 30);

        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'objektplaene');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendReviewReminders::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(SendReviewReminders::class)->dailyAt('07:05')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('objektplaene-manage', fn (User $user) => ObjectPlanService::canManage($user));

        foreach (['library' => Library::class, 'object-detail' => ObjectDetail::class, 'object-form' => ObjectForm::class] as $name => $class) {
            Livewire::component('objektplaene.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'objektplaene',
            'Objektpläne & Brandschutzunterlagen',
            'Digitale Objektpläne, Feuerwehrpläne und Brandschutzunterlagen – sofort verfügbar bei Alarm',
            roles: ['planverwalter'],
            provider: self::class,
            route: 'objektplaene.index',
        );

        // Start page, on top: a running Einsatz at an object with plans – for everyone with access
        app(DashboardRegistry::class)->register('objektplaene', 'einsatz', function (User $user) {
            $running = app(ObjectPlanService::class)->running();

            return $running->isEmpty() ? null : view('objektplaene::cards.einsatz', compact('running'))->render();
        }, DashboardRegistry::TOP);

        // The Planverwalter: reviews that are due
        app(DashboardRegistry::class)->register('objektplaene', 'review', function (User $user) {
            if (! ObjectPlanService::canManage($user)) {
                return null;
            }
            $due = PlanObject::active()->whereNotNull('next_review_on')->where('next_review_on', '<=', ObjectPlanService::today()->toDateString())->count();

            return $due === 0 ? null : view('objektplaene::cards.review', compact('due'))->render();
        });

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('objektplaene', fn (User $u) => [
            'tabs' => [ModuleNav::tab('Bibliothek', 'objektplaene.index', ['objektplaene.object', 'objektplaene.edit'])],
        ]);
    }
}
