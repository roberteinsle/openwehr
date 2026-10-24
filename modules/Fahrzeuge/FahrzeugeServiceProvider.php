<?php

namespace Modules\Fahrzeuge;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\InventoryRegistry;
use App\Services\ModuleRegistry;
use App\Services\ScanRegistry;
use App\Services\VehicleBlockRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Console\Commands\SendFleetReminders;
use Modules\Fahrzeuge\src\Livewire\CallSigns;
use Modules\Fahrzeuge\src\Livewire\Deadlines;
use Modules\Fahrzeuge\src\Livewire\Fleet;
use Modules\Fahrzeuge\src\Livewire\VehicleDetail;
use Modules\Fahrzeuge\src\Livewire\VehicleForm;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Models\Record;
use Modules\Fahrzeuge\src\Services\FleetService;

/**
 * Fahrzeugverwaltung & Flotte: profiles, status and deadlines of the vehicles the Fahrzeugbuchung
 * and the Einsatzbericht share. Its downtimes block bookings through the VehicleBlockRegistry;
 * it shows which vehicle is out at an Einsatz or booked right now.
 */
class FahrzeugeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FleetService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'fahrzeuge');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendFleetReminders::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(SendFleetReminders::class)->dailyAt('06:50')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('fahrzeuge-manage', fn (User $user) => FleetService::canManage($user));

        foreach (['call-signs' => CallSigns::class, 'deadlines' => Deadlines::class, 'fleet' => Fleet::class, 'vehicle-detail' => VehicleDetail::class, 'vehicle-form' => VehicleForm::class] as $name => $class) {
            Livewire::component('fahrzeuge.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'fahrzeuge',
            'Fahrzeugverwaltung & Flotte',
            'Zentrale Verwaltung aller Fahrzeuge mit Stammdaten, Status und Einsatzhistorie',
            roles: ['fahrzeugwart'],
            provider: self::class,
            route: 'fahrzeuge.index',
        );

        // Werkstatt and other downtimes can't be booked in the Fahrzeugbuchung
        app(VehicleBlockRegistry::class)->register('fahrzeuge', [FleetService::class, 'blocks']);

        // The QR label on a vehicle (FZ-<id>) opens its Fahrzeugakte
        app(ScanRegistry::class)->register('fahrzeuge', function (string $code): ?string {
            return preg_match('/^FZ-(\d+)$/i', $code, $m) && Vehicle::whereKey((int) $m[1])->exists() ? route('fahrzeuge.show', (int) $m[1]) : null;
        });

        // Jahresbericht: Prüfungen, Wartungen, Reparaturen and their costs per vehicle
        app(AnnualReportRegistry::class)->register('fahrzeuge', 'Fahrzeuge: Prüfungen, Wartungen & Reparaturen', function (int $year) {
            $records = Record::with('vehicle')->whereYear('done_on', $year)->get();
            if ($records->isEmpty()) {
                return null;
            }
            $rows = $records->groupBy('vehicle_id')->map(fn ($r) => ['name' => $r->first()->vehicle?->name ?? '–',
                'inspection' => $r->where('kind', 'inspection')->count(), 'failed' => $r->whereIn('result', ['limited', 'failed'])->count(),
                'service' => $r->whereIn('kind', ['service', 'other'])->count(), 'repair' => $r->where('kind', 'repair')->count(),
                'cost' => (int) $r->sum('cost_cents')])->sortBy('name')->values();

            return view('fahrzeuge::annual.section', ['rows' => $rows])->render();
        }, 62);

        // Bestand: vehicles by name, Funkrufname, Kennzeichen or FIN; their planned Ersatzbeschaffung
        app(InventoryRegistry::class)->register('fahrzeuge', 'Fahrzeuge', function (string $term, User $user) {
            if (! FleetService::canView($user)) {
                return null;
            }
            $like = '%'.$term.'%';
            $vehicles = Vehicle::query()->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('short_name', 'ilike', $like)
                ->orWhereIn('id', Profile::where('call_sign', 'ilike', $like)->orWhere('license_plate', 'ilike', $like)
                    ->orWhere('vin', 'ilike', $like)->orWhere('vehicle_type', 'ilike', $like)->select('vehicle_id')))
                ->orderBy('sort_order')->orderBy('name')->get();
            $states = app(FleetService::class)->states($vehicles);
            $profiles = Profile::whereIn('vehicle_id', $vehicles->pluck('id'))->get()->keyBy('vehicle_id');
            $tones = ['green' => 'green', 'amber' => 'amber', 'red' => 'red', 'blue' => 'blue', 'gray' => 'gray'];

            return $vehicles->map(fn (Vehicle $v) => ['title' => $v->name, 'code' => collect([$profiles[$v->id]?->call_sign, $profiles[$v->id]?->license_plate])->filter()->implode(' · ') ?: null,
                'place' => $profiles[$v->id]?->categoryLabel(), 'state' => $states[$v->id]['label'], 'tone' => $tones[$states[$v->id]['tone']] ?? 'gray',
                'url' => route('fahrzeuge.show', $v)]);
        }, function (User $user, int $from, int $to) {
            if (! FleetService::canManage($user)) {
                return null;
            }

            return Profile::with('vehicle')->whereNotNull('replacement_year')->where('replacement_year', '<=', $to)
                ->whereHas('vehicle', fn ($q) => $q->where('is_active', true))->get()
                ->map(fn ($p) => ['year' => $p->replacement_year, 'title' => $p->vehicle->name, 'detail' => collect(['Ersatzbeschaffung',
                    $p->purchased_on ? 'beschafft '.$p->purchased_on->format('Y') : ($p->year_built ? 'Baujahr '.$p->year_built : null)])->filter()->implode(' · '),
                    'cost_cents' => $p->replacement_cost_cents, 'url' => route('fahrzeuge.show', $p->vehicle_id)]);
        }, 10);

        // Start page: the fleet at a glance, overdue deadlines for the Fahrzeugwarte
        app(DashboardRegistry::class)->register('fahrzeuge', 'fleet', function (User $user) {
            $vehicles = Vehicle::where('is_active', true)->get();
            if ($vehicles->isEmpty()) {
                return null;
            }
            $states = app(FleetService::class)->states($vehicles)->groupBy('tone')->map->count();
            $overdue = FleetService::canManage($user) ? Deadline::active()->where('due_on', '<', FleetService::today()->toDateString())->count() : 0;

            return view('fahrzeuge::cards.fleet', ['total' => $vehicles->count(), 'states' => $states, 'overdue' => $overdue])->render();
        });

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('fahrzeuge', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Fahrzeuge', 'fahrzeuge.index', ['fahrzeuge.show', 'fahrzeuge.edit']),
                ModuleNav::tab('Funkrufnamen', 'fahrzeuge.call-signs'),
                ModuleNav::tab('Fristen', 'fahrzeuge.deadlines'),
            ],
        ]);
    }
}
