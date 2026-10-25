<?php

namespace Modules\Gefahrstoffe;

use App\Models\User;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GefahrstoffeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'gefahrstoffe');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        app(ModuleRegistry::class)->register(
            'gefahrstoffe',
            'Gefahrstoffe',
            'Gefahrenmatrix und Gefahrnummern der Warntafel nachschlagen, auch ohne Netz',
            provider: self::class,
            route: 'gefahrstoffe.index',
        );

        app(ModuleRegistry::class)->navigation('gefahrstoffe', fn (User $u) => [
            'tabs' => [ModuleNav::tab('Nachschlagen', 'gefahrstoffe.index')],
        ]);
    }
}
