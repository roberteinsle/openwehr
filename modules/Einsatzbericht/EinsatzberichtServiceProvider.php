<?php

namespace Modules\Einsatzbericht;

use App\Models\Alarm;
use App\Models\User;
use App\Services\AlarmRegistry;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\HoursRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use App\Support\ModuleNav;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Console\Commands\SendReportReminders;
use Modules\Einsatzbericht\src\Filament\Pages\SettingsPage;
use Modules\Einsatzbericht\src\Livewire\ExternalReport;
use Modules\Einsatzbericht\src\Livewire\MaterialCatalog;
use Modules\Einsatzbericht\src\Livewire\MemberMissions;
use Modules\Einsatzbericht\src\Livewire\Members;
use Modules\Einsatzbericht\src\Livewire\ReportEditor;
use Modules\Einsatzbericht\src\Livewire\ReportList;
use Modules\Einsatzbericht\src\Livewire\SituationLog;
use Modules\Einsatzbericht\src\Livewire\Statistics;
use Modules\Einsatzbericht\src\Models\DiveraRequest;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\DiveraWebhook;
use Modules\Einsatzbericht\src\Services\ExternalAccess;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Einsatzbericht\src\Services\SituationService;

/**
 * Einsatzbericht: the Einsatzleiter records each Einsatz once, the Wehrführung releases it. The
 * same data gives the Jahresstatistik and the Einsätze in the Personalakte of every Kamerad.
 */
class EinsatzberichtServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportService::class);
        $this->app->singleton(DiveraWebhook::class);
        $this->app->singleton(SituationService::class);
        $this->app->singleton(ExternalAccess::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'einsatzbericht');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
            Route::group([], $base.'/routes/webhooks.php');
        }

        // Alarms of the core DIVERA webhook (Admin → DIVERA-Webhook) become drafts like those of the
        // module's own address, as long as "Entwürfe aus DIVERA" is on here; tests and updates don't
        app(AlarmRegistry::class)->listen('einsatzbericht', function (Alarm $alarm, bool $isNew) {
            if (! $isNew || $alarm->isTest() || ! DiveraWebhook::enabled()) {
                return;
            }
            $entry = DiveraRequest::create([
                'received_at' => $alarm->received_at, 'method' => 'POST', 'content_type' => 'application/json (DIVERA-Webhook im Admin-Bereich)',
                'body' => json_encode($alarm->payload ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'status' => 'pending',
            ]);
            app(DiveraWebhook::class)->process($entry);
        });

        // DIVERA: at most 30 requests a minute per address
        // Per address (a real alarm burst) and per sender IP (wrong keys tried out)
        RateLimiter::for('divera', fn (Request $request) => [Limit::perMinute(30)->by('divera|'.$request->route('key')), Limit::perMinute(60)->by('divera-ip|'.$request->ip())]);
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendReportReminders::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(SendReportReminders::class)->dailyAt('08:00')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('einsatzbericht-lead', fn (User $user) => ReportService::canLead($user));
        Gate::define('einsatzbericht-release', fn (User $user) => ReportService::canRelease($user));
        Gate::define('einsatzbericht-materials', fn (User $user) => $user->is_admin && $user->is_active);

        foreach (['report-list' => ReportList::class, 'report-editor' => ReportEditor::class, 'statistics' => Statistics::class,
            'members' => Members::class, 'member-missions' => MemberMissions::class,
            'situation-log' => SituationLog::class, 'material-catalog' => MaterialCatalog::class, 'external-report' => ExternalReport::class] as $name => $class) {
            Livewire::component('einsatzbericht.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'einsatzbericht',
            'Einsatzbericht',
            'Einsätze digital erfassen und freigeben, Besatzung und Stärke, Jahresstatistik und Einsätze in der Personalakte',
            roles: ['einsatzleiter', 'wehrführung'],
            provider: self::class,
            route: 'einsatzbericht.index',
        );

        // The own Einsätze right on the Profil
        app(PersonnelFileRegistry::class)->section('einsatzbericht', 'Meine Einsätze', 'einsatzbericht.member-missions');

        // Start page: drafts and current Einsätze for the Einsatzleiter, reports to release for the Wehrführung
        app(DashboardRegistry::class)->register('einsatzbericht', 'open', function (User $user) {
            $lead = ReportService::canLead($user);
            $drafts = $lead ? Report::where('status', 'draft')->count() : 0;
            $active = $lead ? Report::where('status', 'active')->count() : 0;
            $toRelease = ReportService::canRelease($user) ? Report::where('status', 'closed')->count() : 0;

            return $drafts + $active + $toRelease === 0 ? null : view('einsatzbericht::cards.open', compact('drafts', 'active', 'toRelease'))->render();
        });

        // Jahresbericht: the Einsätze come first
        // Stundennachweis: every closed or released Einsatz in the crew, from Alarmierung to Einsatzende
        app(HoursRegistry::class)->register('einsatzbericht', 'Einsätze', fn (array $ids, Carbon $from, Carbon $to) => Report::whereIn('status', Report::DONE)
            ->whereBetween('alarmed_at', [$from, $to])->with(['crew' => fn ($q) => $q->whereIn('user_id', $ids)])
            ->whereHas('crew', fn ($q) => $q->whereIn('user_id', $ids))->get()
            ->flatMap(fn (Report $r) => $r->crew->map(fn ($c) => ['user_id' => $c->user_id, 'date' => $r->alarmed_at,
                'title' => 'Einsatz '.$r->number.($r->keyword ? ' · '.$r->keyword : ''), 'minutes' => min(2880, (int) $r->durationMinutes())])));

        app(AnnualReportRegistry::class)->register('einsatzbericht', 'Einsätze', fn (int $year) => view('einsatzbericht::annual.section', [
            'stats' => app(ReportService::class)->statistics($year),
        ])->render(), 10);

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('einsatzbericht', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Einsätze', 'einsatzbericht.index', ['einsatzbericht.show', 'einsatzbericht.situation']),
                ReportService::canRelease($u) ? ModuleNav::tab('Jahresstatistik', 'einsatzbericht.statistics', ['einsatzbericht.annual']) : null,
                ReportService::canRelease($u) ? ModuleNav::tab('Kameraden', 'einsatzbericht.members', ['einsatzbericht.member']) : null,
                $u->can('einsatzbericht-materials') ? ModuleNav::tab('Material', 'einsatzbericht.materials') : null,
            ],
            'manage' => ReportService::canRelease($u) ? SettingsPage::getUrl() : null,
        ]);
    }
}
