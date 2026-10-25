<?php

use App\Livewire\Dashboard\Home;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleOverview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\CalendarView;
use Modules\Uebungsdienste\src\Services\ServicePlan;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    Module::where('key', 'uebungsdienste')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    $this->aktive = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $this->jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
});

test('Kameraden in several Abteilungen see for which one a Termin or Übungsdienst is; with one Abteilung nothing changes', function () {
    $both = kalenderUser(null, ['name' => 'Hanna Hydrant']);
    $both->groups()->attach([$this->aktive->id, $this->jf->id]);
    $one = kalenderUser(null, ['name' => 'Bernd Blaulicht']);
    $one->groups()->attach($this->jf);
    kalenderEvent('2027-06-03 18:00', '20:00', ['title' => 'Übungsdienst'], [$this->jf->id]);
    app(ServicePlan::class)->add(User::factory()->admin()->create(), '2027-06-03', '18:00', 'Wache', ['owner_group_id' => $this->jf->id]);

    Livewire::actingAs($both)->test(Home::class)->assertSee('Für: Jugendfeuerwehr');
    Livewire::actingAs($both)->test(CalendarView::class)->assertSee('Jugendfeuerwehr');
    Livewire::actingAs($one)->test(Home::class)->assertDontSee('Für: Jugendfeuerwehr');
});

test('the start page cards follow the Kamerad\'s own order of the modules', function () {
    $user = kalenderUser(null, ['name' => 'Hanna Hydrant']);
    $user->moduleAccess()->create(['module' => 'uebungsdienste']);
    $user->update(['modules_locked' => true]);
    kalenderEvent('2027-06-03 18:00', '20:00', ['title' => 'Kalendertermin']);
    app(ServicePlan::class)->add(User::factory()->admin()->create(), '2027-06-04', '18:00', 'Wache');

    $overview = app(ModuleOverview::class);
    $keys = array_keys($overview->for($user));
    $overview->reorder($user, ['uebungsdienste', ...array_diff($keys, ['uebungsdienste'])]);
    Livewire::actingAs($user->fresh())->test(Home::class)->assertSeeInOrder(['Nächster Übungsdienst', 'Nächster Termin']);

    $overview->reorder($user, ['kalender', ...array_diff($keys, ['kalender'])]);
    Livewire::actingAs($user->fresh())->test(Home::class)->assertSeeInOrder(['Nächster Termin', 'Nächster Übungsdienst']);
});
