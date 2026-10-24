<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleOverview;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Module::whereIn('key', ['lehrgaenge', 'schichtplan', 'ausruestung', 'personalverwaltung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    foreach (['lehrgaenge', 'schichtplan', 'ausruestung'] as $module) {
        $this->hanna->moduleAccess()->create(['module' => $module]);
    }
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->bernd->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'ausbildungsleiter']);
    $this->bernd->moduleAccess()->create(['module' => 'schichtplan', 'role' => 'schichtplaner']);
    $this->bernd->moduleAccess()->create(['module' => 'ausruestung', 'role' => 'geraetewart']);
});

test('a module shows its Modulleiste with only the tabs the Kamerad may open', function () {
    $this->actingAs($this->hanna)->get(route('lehrgaenge.index'))->assertOk()
        ->assertSee('data-module-bar', false)->assertSee('Lehrgänge')
        ->assertDontSee('href="'.route('lehrgaenge.courses').'"', false)->assertDontSee('href="'.route('lehrgaenge.registrations').'"', false)->assertDontSee('href="'.route('lehrgaenge.import').'"', false);

    $this->actingAs($this->bernd)->get(route('lehrgaenge.index'))->assertOk()
        ->assertSee('href="'.route('lehrgaenge.courses').'"', false)->assertSee('href="'.route('lehrgaenge.registrations').'"', false)->assertSee('href="'.route('lehrgaenge.import').'"', false);
});

test('the Personalakte has its own bar, not the one of the module that adds the page', function () {
    $html = $this->actingAs($this->bernd)->get(route('lehrgaenge.personnel-file'))->assertOk()->getContent();
    expect($html)->toContain('Meine Personalakte')->toContain('href="'.route('profile').'"')
        ->not->toContain('Lehrgangsliste')->not->toContain('href="'.route('lehrgaenge.import').'"');
});

test('the main action sits once next to the page title, Verwaltung only for the roles', function () {
    $this->actingAs($this->hanna)->get(route('schichtplan.index'))->assertOk()->assertDontSee('href="'.route('schichtplan.create').'"', false);
    $html = $this->actingAs($this->bernd)->get(route('schichtplan.index'))->assertOk()->getContent();
    expect(substr_count($html, 'href="'.route('schichtplan.create').'"'))->toBe(1);
    $this->actingAs($this->bernd)->get(route('schichtplan.create'))->assertOk()->assertSee('href="'.route('schichtplan.index').'"', false);

    $this->actingAs($this->hanna)->get(route('ausruestung.equipment'))->assertOk()->assertDontSee('title="Verwaltung"', false)->assertDontSee('Inventur')
        ->assertDontSee('>Admin</a>', false);
    // The Gerätewart's admin area is part of /admin, so the blue bar offers it too
    $this->actingAs($this->bernd)->get(route('ausruestung.equipment'))->assertOk()
        ->assertSee('href="'.route('filament.admin.pages.ausruestung').'" title="Verwaltung"', false)->assertSee('Inventur')
        ->assertSee('>Admin</a>', false);
});

test('no Modulleiste outside modules and for modules the Kamerad has no access to', function () {
    $this->actingAs($this->hanna)->get(route('home'))->assertOk()->assertDontSee('data-module-bar', false);
    $this->actingAs($this->hanna)->get(route('personalverwaltung.index'))->assertForbidden();
});

test('Personalverwaltung and Unterweisungen open the module for role holders, the Personalakte for everyone else', function () {
    Module::whereIn('key', ['unterweisungen', 'personalverwaltung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    foreach (['unterweisungen', 'personalverwaltung'] as $module) {
        $this->hanna->moduleAccess()->create(['module' => $module]);
    }
    $this->bernd->moduleAccess()->create(['module' => 'unterweisungen', 'role' => 'unterweisungsbeauftragter']);
    $this->bernd->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    $overview = app(ModuleOverview::class);

    expect($overview->for($this->bernd)['unterweisungen']['url'])->toBe(route('unterweisungen.overview'))
        ->and($overview->for($this->bernd)['personalverwaltung']['url'])->toBe(route('personalverwaltung.index'))
        ->and($overview->for($this->hanna)['unterweisungen']['url'])->toBe(route('unterweisungen.personnel-file'))
        ->and($overview->for($this->hanna)['personalverwaltung']['url'])->toBe(route('personalverwaltung.own'));

    $this->actingAs($this->bernd)->get(route('unterweisungen.overview'))->assertOk()->assertSee('href="'.route('unterweisungen.new').'"', false)->assertDontSee('Meine Unterweisungen');
});
