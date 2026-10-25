<?php

namespace Modules\Kalender;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\HoursRegistry;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Kalender\src\Console\Commands\RemindPendingResponses;
use Modules\Kalender\src\Console\Commands\SyncCalendarSources;
use Modules\Kalender\src\Livewire\CalendarView;
use Modules\Kalender\src\Livewire\EventDetail;
use Modules\Kalender\src\Livewire\EventForm;
use Modules\Kalender\src\Livewire\EventTypes;
use Modules\Kalender\src\Livewire\PollForm;
use Modules\Kalender\src\Livewire\PollList;
use Modules\Kalender\src\Livewire\PollView;
use Modules\Kalender\src\Livewire\Sources;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Poll;
use Modules\Kalender\src\Models\PollVote;
use Modules\Kalender\src\Models\Response;
use Modules\Kalender\src\Policies\EventPolicy;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Services\FeedService;
use Modules\Kalender\src\Services\IcsExporter;
use Modules\Kalender\src\Services\IcsParser;
use Modules\Kalender\src\Services\PollService;
use Modules\Kalender\src\Services\SourceSync;

class KalenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([EventService::class, PollService::class, IcsParser::class, IcsExporter::class, SourceSync::class, FeedService::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'kalender');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SyncCalendarSources::class, RemindPendingResponses::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            // External calendars are pulled in every hour
            $schedule->command(SyncCalendarSources::class)->hourly()->withoutOverlapping()->appendOutputTo($output);

            // Kameraden who have not answered are reminded once, shortly before the deadline or the event
            $schedule->command(RemindPendingResponses::class)->hourly()->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::policy(Event::class, EventPolicy::class);

        Livewire::component('kalender.calendar-view', CalendarView::class);
        Livewire::component('kalender.event-types', EventTypes::class);
        Livewire::component('kalender.event-form', EventForm::class);
        Livewire::component('kalender.event-detail', EventDetail::class);
        Livewire::component('kalender.sources', Sources::class);
        Livewire::component('kalender.poll-list', PollList::class);
        Livewire::component('kalender.poll-form', PollForm::class);
        Livewire::component('kalender.poll-view', PollView::class);

        app(ModuleRegistry::class)->register(
            'kalender',
            'Kalender & Termine',
            'Übungen, Dienste, Arbeitsdienste und Veranstaltungen mit Rückmeldung und Terminabfrage',
            roles: ['terminverwalter'],
            provider: self::class,
            route: 'kalender.index',
        );

        // The next event on the personal start page, with the Kamerad's own Rückmeldung
        app(DashboardRegistry::class)->register('kalender', 'next-event', function (User $user) {
            $event = Event::visibleTo($user)->upcoming()->with('groups')->first();

            return $event ? view('kalender::cards.next-event', [
                'event' => $event,
                'response' => $event->responses()->where('user_id', $user->id)->first(),
                'canOpen' => $user->canUseModule('kalender'),
            ])->render() : null;
        }, everyone: true);

        // Start page: Terminabfragen the Kamerad has not answered yet
        app(DashboardRegistry::class)->register('kalender', 'polls', function (User $user) {
            $answered = PollVote::where('user_id', $user->id)->join('kal_poll_options', 'kal_poll_options.id', '=', 'kal_poll_votes.option_id')->pluck('kal_poll_options.poll_id');
            $polls = Poll::visibleTo($user)->whereNull('closed_at')->whereNotIn('id', $answered)->latest()->get()
                ->filter(fn (Poll $p) => $p->isOpen() && app(PollService::class)->audience($p)->contains('id', $user->id))->values();

            return $polls->isEmpty() ? null : view('kalender::cards.polls', compact('polls'))->render();
        });

        // Jahresbericht: the Termine of the year, with the attendance where it was taken
        // Stundennachweis: the Dienste and Termine a Kamerad was marked present at (all-day ones have no hours)
        app(HoursRegistry::class)->register('kalender', 'Dienste und Termine', fn (array $ids, Carbon $from, Carbon $to) => Event::query()
            ->join('kal_responses', 'kal_responses.event_id', '=', 'kal_events.id')
            ->whereIn('kal_responses.user_id', $ids)->where('kal_responses.attended', true)
            ->whereNull('kal_events.source_id')->whereNull('kal_events.cancelled_at')->where('kal_events.all_day', false)
            ->whereBetween('kal_events.starts_at', [$from, $to])
            ->get(['kal_events.*', 'kal_responses.user_id as attendee_id'])
            ->map(fn (Event $e) => ['user_id' => (int) $e->attendee_id, 'date' => $e->starts_at, 'title' => $e->typeLabel().': '.$e->title,
                'minutes' => min(1440, (int) $e->starts_at->diffInMinutes($e->ends_at))]));

        app(AnnualReportRegistry::class)->register('kalender', 'Dienste und Termine', function (int $year) {
            $from = Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc();
            $events = Event::whereNull('source_id')->whereNull('cancelled_at')->whereBetween('starts_at', [$from, $from->copy()->addYear()]);
            $byType = (clone $events)->selectRaw('type, count(*) as total')->groupBy('type')->orderByDesc('total')->pluck('total', 'type');
            if ($byType->isEmpty()) {
                return null;
            }
            $ids = (clone $events)->pluck('id');

            return view('kalender::annual.section', [
                'byType' => $byType,
                'tracked' => Response::whereIn('event_id', $ids)->whereNotNull('attended')->distinct()->count('event_id'),
                'attendances' => Response::whereIn('event_id', $ids)->where('attended', true)->count(),
            ])->render();
        }, 30);

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('kalender', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Kalender', 'kalender.index', ['kalender.show', 'kalender.edit', 'kalender.create']),
                ModuleNav::tab('Terminabfragen', 'kalender.polls', ['kalender.polls.*']),
                ModuleNav::tab('Dienstplan', 'kalender.roster'),
                $u->canManageKalender() ? ModuleNav::tab('Orte & Kleidung', 'kalender.places') : null,
                $u->canManageKalender() ? ModuleNav::tab('Externe Kalender', 'kalender.sources') : null,
                $u->is_admin ? ModuleNav::tab('Terminarten', 'kalender.types') : null,
            ],
        ]);
    }
}
