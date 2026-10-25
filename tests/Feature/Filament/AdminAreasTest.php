<?php

use App\Models\Module;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

// One admin panel: admins see everything, module managers only their module's group

beforeEach(function () {
    Module::query()->whereIn('key', ['kleiderkammer', 'ausruestung', 'maengelmanagement'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->kleiderwart = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->kleiderwart->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']);
});

test('a Kleiderwart lands in the Kleiderkammer area and sees only its group', function () {
    $this->actingAs($this->kleiderwart)->get('/admin')->assertRedirect('/admin/kleiderkammer');

    $this->actingAs($this->kleiderwart)->get('/admin/kleiderkammer')->assertOk()
        ->assertSee('Kleiderkammer')
        ->assertSee('href="'.url('/admin/kleiderkammer/einzelteile').'"', false)
        ->assertDontSee('href="'.url('/admin/ausruestung').'"', false)
        ->assertDontSee('href="'.url('/admin/maengelmanagement').'"', false)
        ->assertDontSee('href="'.url('/admin/fahrzeugbuchung').'"', false)
        ->assertDontSee('Wachen-Monitor')
        ->assertDontSee('href="'.url('/admin/kameraden').'"', false)
        ->assertDontSee('href="'.url('/admin/logs').'"', false);
});

test('a Kleiderwart can not open core pages or other module areas', function (string $path) {
    $this->actingAs($this->kleiderwart)->get($path)->assertForbidden();
})->with([
    '/admin/kameraden',
    '/admin/logs',
    '/admin/abteilungen',
    '/admin/funktionen',
    '/admin/feedback',
    '/admin/ansprechpartner',
    '/admin/ansprechpartner-gruppen',
    '/admin/module',
    '/admin/einstellungen',
    '/admin/kleiderkammer/datentransfer',
    '/admin/ausruestung',
    '/admin/ausruestung/geraete',
    '/admin/maengelmanagement/maengel',
    '/admin/fahrzeugbuchung',
    '/admin/fahrzeugbuchung/buchungen',
]);

test('a Kamerad who manages nothing can not open /admin', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

test('someone managing two modules sees both groups', function () {
    $this->kleiderwart->moduleAccess()->create(['module' => 'ausruestung', 'role' => 'geraetewart']);

    $this->actingAs($this->kleiderwart)->get('/admin/ausruestung')->assertOk()
        ->assertSee('href="'.url('/admin/kleiderkammer').'"', false);
    $this->actingAs($this->kleiderwart)->get('/admin/ausruestung/geraete')->assertOk();
});

test('admins see the core and every module group', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('href="'.url('/admin/kameraden').'"')
        ->toContain('href="'.url('/admin/fahrzeugbuchung').'"')
        ->toContain('href="'.url('/admin/kleiderkammer').'"')
        ->toContain('href="'.url('/admin/ausruestung').'"')
        ->toContain('href="'.url('/admin/maengelmanagement').'"');
});

test('global search only offers resources of the own area', function () {
    $this->actingAs($this->kleiderwart);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $searchable = collect(Filament::getResources())->filter(fn (string $resource) => $resource::canAccess());

    expect($searchable)->not->toBeEmpty()
        ->and($searchable->every(fn (string $resource) => str_starts_with($resource, 'Modules\\Kleiderkammer\\')))->toBeTrue();
});

test('the Wehrführung and Personalverwalter find their module settings in /admin', function () {
    Module::query()->whereIn('key', ['einsatzbericht', 'personalverwaltung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $chief = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $chief->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'wehrführung']);

    $this->actingAs($chief)->get('/admin')->assertRedirect('/admin/einsatzbericht/einstellungen');
    $this->actingAs($chief)->get('/admin/einsatzbericht/einstellungen')->assertOk()
        ->assertSee('Pflichtfelder')
        ->assertDontSee('href="'.url('/admin/personalverwaltung/einstellungen').'"', false)
        ->assertDontSee('href="'.url('/admin/kleiderkammer').'"', false);
    $this->actingAs($chief)->get('/admin/personalverwaltung/einstellungen')->assertForbidden();

    $chief->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    $this->actingAs($chief)->get('/admin/personalverwaltung/einstellungen')->assertOk()->assertSee('Dienstzeit');
});
