<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\CalendarView;
use Modules\Kalender\src\Livewire\EventTypes;
use Modules\Kalender\src\Support\Birthdays;

beforeEach(function () {
    Module::where('key', 'kalender')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-03-10 12:00', 'Europe/Berlin'));
    $this->member = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->member->moduleAccess()->create(['module' => 'kalender']);
});

test('birthdays of active Kameraden appear in the month, without year', function () {
    User::factory()->create(['name' => 'Hanna Hydrant', 'birth_date' => '1990-03-15']);
    User::factory()->create(['name' => 'Ausgetreten Alt', 'birth_date' => '1980-03-16', 'status' => 'left']);
    User::factory()->create(['name' => 'Ohne Datum']);

    Livewire::actingAs($this->member)->test(CalendarView::class, [])->set('view', 'monat')
        ->assertSee('Hanna Hydrant')->assertSee('Geburtstag: Hanna Hydrant', false)->assertDontSee('1990')
        ->assertDontSee('Ausgetreten Alt');

    Livewire::actingAs($this->member)->test(CalendarView::class)->set('view', 'liste')->assertSee('Geburtstag')->assertSee('Hanna Hydrant');
});

test('29 February is on 28 February in other years', function () {
    User::factory()->create(['name' => 'Schalt Jahr', 'birth_date' => '2000-02-29']);

    expect(Birthdays::between(Carbon::parse('2027-02-01', 'Europe/Berlin'), Carbon::parse('2027-03-01', 'Europe/Berlin'))->all())
        ->toBe(['2027-02-28' => ['Schalt Jahr']]);
});

test('a type filter and the admin switch hide the birthdays', function () {
    User::factory()->create(['name' => 'Hanna Hydrant', 'birth_date' => '1990-03-15']);

    Livewire::actingAs($this->member)->test(CalendarView::class)->set('view', 'monat')->set('type', 'uebung')->assertDontSee('Hanna Hydrant');

    Livewire::actingAs(User::factory()->admin()->create())->test(EventTypes::class)->set('birthdays', false);
    expect(Birthdays::enabled())->toBeFalse();
    Livewire::actingAs($this->member)->test(CalendarView::class)->set('view', 'monat')->assertDontSee('Hanna Hydrant');
});
