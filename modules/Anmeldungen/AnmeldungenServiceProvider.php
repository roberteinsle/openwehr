<?php

namespace Modules\Anmeldungen;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Services\ParentRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Anmeldungen\src\Console\Commands\PurgeHealthData;
use Modules\Anmeldungen\src\Livewire\FormEditor;
use Modules\Anmeldungen\src\Livewire\FormList;
use Modules\Anmeldungen\src\Livewire\FormView;
use Modules\Anmeldungen\src\Livewire\ParentForms;
use Modules\Anmeldungen\src\Livewire\RegistrationForm;
use Modules\Anmeldungen\src\Models\Form;
use Modules\Anmeldungen\src\Models\Registration;
use Modules\Anmeldungen\src\Services\RegistrationService;

/**
 * Anmeldungen & Einverständnisse: forms for Fahrten, Zeltlager and Veranstaltungen with own fields, the
 * declarations to accept and a signature on screen – by the Kamerad, or by the Erziehungsberechtigten in
 * the Eltern-Bereich (ParentRegistry). Health answers are encrypted and wiped after the event.
 */
class AnmeldungenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegistrationService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'anmeldungen');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([PurgeHealthData::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(PurgeHealthData::class)->dailyAt('03:40')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('anmeldungen-manage', fn (User $user) => RegistrationService::canManage($user));

        foreach (['form-list' => FormList::class, 'form-view' => FormView::class, 'form-editor' => FormEditor::class,
            'registration-form' => RegistrationForm::class, 'parent-forms' => ParentForms::class] as $name => $class) {
            Livewire::component('anmeldungen.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'anmeldungen',
            'Anmeldungen',
            'Anmeldungen und Einverständniserklärungen für Fahrten, Zeltlager und Veranstaltungen – eigene Felder, Unterschrift am Bildschirm, auch durch die Eltern',
            roles: [RegistrationService::ROLE],
            provider: self::class,
            route: 'anmeldungen.index',
        );

        // Eltern-Bereich: the child's open Anmeldungen, filled in and signed by the parent
        app(ParentRegistry::class)->section('anmeldungen', 'Anmeldungen', 'anmeldungen.parent-forms', order: 20,
            visible: fn (User $child) => app(RegistrationService::class)->openFor($child)->isNotEmpty()
                || Registration::where('user_id', $child->id)->where('status', 'registered')->whereHas('form', fn ($q) => $q->where('status', '!=', 'draft'))->exists());

        // Start page: open Anmeldungen the Kamerad still has to fill in; organisers the numbers of the open ones
        app(DashboardRegistry::class)->register('anmeldungen', 'open', function (User $user) {
            $service = app(RegistrationService::class);
            $done = Registration::where('user_id', $user->id)->where('status', 'registered')->pluck('form_id')->all();
            $lines = $service->openFor($user)->filter(fn (Form $f) => $f->acceptsRegistrations() && ! in_array($f->id, $done, true) && $service->maySelfRegister($f, $user))
                ->map(fn (Form $f) => 'Anmeldung offen: '.$f->title.($f->deadline ? ' – bis '.$f->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.') : ''))->all();
            if (RegistrationService::canManage($user)) {
                foreach (Form::where('status', 'open')->withCount(['registrations as n' => fn ($q) => $q->where('status', 'registered')])->get() as $f) {
                    $lines[] = $f->title.': '.$f->n.' angemeldet';
                }
            }

            return $lines === [] ? null : view('anmeldungen::cards.open', ['lines' => array_values($lines)])->render();
        });

        // Jahresbericht: Fahrten and Veranstaltungen with Anmeldung and how many took part
        app(AnnualReportRegistry::class)->register('anmeldungen', 'Fahrten und Veranstaltungen mit Anmeldung', function (int $year) {
            $forms = Form::where('status', '!=', 'draft')->whereYear('starts_on', $year)->withCount(['registrations as n' => fn ($q) => $q->where('status', 'registered')])->orderBy('starts_on')->get();
            if ($forms->isEmpty()) {
                return null;
            }

            return '<ul>'.$forms->map(fn (Form $f) => '<li>'.e($f->title).' ('.e((string) $f->dateLabel()).'): '.$f->n.' Teilnehmer</li>')->implode('').'</ul>';
        }, order: 75);

        // The Modulleiste
        app(ModuleRegistry::class)->navigation('anmeldungen', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Anmeldungen', 'anmeldungen.index', ['anmeldungen.show', 'anmeldungen.create', 'anmeldungen.edit']),
            ],
        ]);
    }
}
