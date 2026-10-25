<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Gefahrstoffe\src\Support\HazardNumbers;

function activateGefahrstoffe(): void
{
    Module::where('key', 'gefahrstoffe')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
}

test('the module starts switched off and then is open to every Kamerad', function () {
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    expect(Module::find('gefahrstoffe'))->is_active->toBeFalse()->auto_assign->toBeTrue();
    $this->actingAs($hanna)->get('/gefahrstoffe')->assertNotFound();

    activateGefahrstoffe();
    $this->actingAs($hanna)->get('/gefahrstoffe')->assertOk()
        ->assertSee('Gefahrenmatrix (AAAA-C-EEEE)')->assertSee('Atemgifte')->assertSee('Einsturz')
        ->assertSee('GAMS')->assertSee('leicht entzündbarer flüssiger Stoff (Flammpunkt unter 23 °C)')
        ->assertSee('X423')->assertSee('ADR 2025, Unterabschnitt 5.3.2.3');
});

test('a Gefahrnummer is looked up, or read from its digits when it is not listed', function () {
    expect(HazardNumbers::lookup(' x 423 '))->toMatchArray(['code' => 'X423', 'water' => true])
        ->and(HazardNumbers::lookup('33')['meaning'])->toBe('leicht entzündbarer flüssiger Stoff (Flammpunkt unter 23 °C)')
        ->and(HazardNumbers::lookup('1203'))->toBeNull()
        ->and(HazardNumbers::lookup('abc'))->toBeNull();

    $unlisted = HazardNumbers::lookup('367');
    expect($unlisted['meaning'])->toBeNull()
        ->and($unlisted['digits'])->toBe([HazardNumbers::data()['digits']['3'], HazardNumbers::data()['digits']['6'], HazardNumbers::data()['digits']['7']]);

    // Every listed number is well-formed, so the page lookup finds it
    foreach (array_keys(HazardNumbers::data()['numbers']) as $code) {
        expect(HazardNumbers::normalize((string) $code))->toBe((string) $code);
    }
});

test('Warntafeln in the Einsatzbericht are explained; reports without Gefahrstoff show nothing', function () {
    activateGefahrstoffe();
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $leader = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);

    $report = app(ReportService::class)->create($leader);
    $this->actingAs($leader)->get(route('einsatzbericht.show', $report))->assertOk()->assertDontSee('Nachschlagen');

    $report->update(['category' => 'th', 'details' => ['kind' => 'Gefahrstoff', 'hazmat' => 'Tankwagen 33/1203, Anhänger X423 – 1428']]);
    $this->actingAs($leader)->get(route('einsatzbericht.show', $report))->assertOk()
        ->assertSee('Nachschlagen')->assertSee('1203')
        ->assertSee('leicht entzündbarer flüssiger Stoff (Flammpunkt unter 23 °C)')
        ->assertSee('Reagiert gefährlich mit Wasser');

    expect(HazardNumbers::panelsIn('ca. 30 Liter ausgelaufen'))->toBe([]);
});

test('the service worker keeps the Gefahrstoffe page for offline use', function () {
    expect(file_get_contents(public_path('sw.js')))->toContain("const OFFLINE_PAGES = ['/gefahrstoffe']");
});
