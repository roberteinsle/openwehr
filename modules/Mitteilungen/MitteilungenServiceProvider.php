<?php

namespace Modules\Mitteilungen;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Mitteilungen\src\Livewire\Editor;
use Modules\Mitteilungen\src\Livewire\Manage;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Services\AnnouncementService;

class MitteilungenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnnouncementService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'mitteilungen');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        Gate::define('post-announcements', fn (User $user) => $user->canPostAnnouncements());

        Livewire::component('mitteilungen.manage', Manage::class);
        Livewire::component('mitteilungen.editor', Editor::class);

        app(ModuleRegistry::class)->register(
            'mitteilungen',
            'Mitteilungen',
            'Nachrichten der Wehrführung an die Kameraden, wichtige ganz oben',
            roles: ['redakteur'],
            provider: self::class,
            route: 'mitteilungen.index',
        );

        // On the start page, full width on top – only when there is something to read
        app(DashboardRegistry::class)->register('mitteilungen', 'board', function (User $user) {
            $announcements = Announcement::visibleTo($user)->with(['author', 'files'])->orderByDesc('important')->latest()->get();

            return $announcements->isEmpty() ? null : view('mitteilungen::cards.board', [
                'announcements' => $announcements,
                'canPost' => $user->canPostAnnouncements(),
                'acknowledged' => DB::table('announcement_reads')->where('user_id', $user->id)->whereNotNull('acknowledged_at')->pluck('announcement_id')->all(),
            ])->render();
        }, DashboardRegistry::TOP);

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('mitteilungen', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Mitteilungen', 'mitteilungen.index'),
                $u->can('post-announcements') ? ModuleNav::tab('Verwalten', 'mitteilungen.manage', ['mitteilungen.create', 'mitteilungen.edit']) : null,
            ],
        ]);
    }
}
