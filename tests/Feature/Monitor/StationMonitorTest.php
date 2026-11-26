<?php

use App\Http\Middleware\NoIndex;
use App\Models\User;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\MonitorLinkWidget;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\TopBookersWidget;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\VehicleBookingsWidget;
use Modules\Fahrzeugbuchung\src\Livewire\Monitor\StationBoard;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

beforeEach(function () {
    config(['monitor.token' => 'geheimer-wachen-schluessel']);

    $this->mtw = Vehicle::factory()->create(['name' => 'MTW neu', 'short_name' => 'MTW-N', 'sort_order' => 1]);
    $this->anh = Vehicle::factory()->create(['name' => 'JF-Anhänger', 'short_name' => 'JF-Anh', 'sort_order' => 2]);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);

    // MTW is out right now, the trailer is free
    Booking::factory()->create([
        'vehicle_id' => $this->mtw->id,
        'user_id' => $this->member->id,
        'purpose' => 'Jugendfeuerwehr Ausflug',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(2),
    ]);
});

test('the station link with the right key opens the monitor without login', function () {
    $this->get('/fahrzeugbuchung/monitor/geheimer-wachen-schluessel')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', NoIndex::HEADER)
        ->assertSee('Fahrzeugbelegung')
        ->assertSee('wire:poll.60s', false);

    expect(auth()->check())->toBeFalse();
});

test('without or with a wrong key the monitor does not exist', function () {
    $this->get('/fahrzeugbuchung/monitor')->assertNotFound();
    $this->get('/fahrzeugbuchung/monitor/falsch')->assertNotFound();
    $this->actingAs($this->member)->get('/fahrzeugbuchung/monitor')->assertNotFound();
});

test('no key configured means admins only', function () {
    config(['monitor.token' => '']);

    $this->get('/fahrzeugbuchung/monitor/')->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get('/fahrzeugbuchung/monitor')->assertOk();
});

test('monitor shows vehicle status, short names and nothing to edit', function () {
    Livewire::test(StationBoard::class)
        ->assertSee('MTW-N')
        ->assertSee('Unterwegs bis')
        ->assertSee('Jugendfeuerwehr Ausflug')
        ->assertSee('Hanna H.')
        ->assertDontSee('Hanna Hydrant')
        ->assertSee('JF-Anh')
        ->assertSee('Frei')
        ->assertDontSee('Bearbeiten')
        ->assertDontSee('Stornieren')
        ->assertDontSeeHtml('wire:click');
});

test('a booking within two hours marks the vehicle as soon booked', function () {
    Booking::factory()->create([
        'vehicle_id' => $this->anh->id,
        'starts_at' => now()->addMinutes(90),
        'ends_at' => now()->addMinutes(150),
    ]);

    Livewire::test(StationBoard::class)->assertSee('gebucht');
});

test('short names keep first name and last initial', function () {
    expect(StationBoard::shortName('Hanna Hydrant'))->toBe('Hanna H.')
        ->and(StationBoard::shortName('Anna Maria von Berg'))->toBe('Anna B.')
        ->and(StationBoard::shortName('Cher'))->toBe('Cher');
});

test('admin dashboard ranks bookers and vehicles', function () {
    $top = User::factory()->create(['name' => 'Viel Bucher']);
    Booking::factory()->count(3)->sequence(
        ['starts_at' => now()->addDays(1), 'ends_at' => now()->addDays(1)->addHour()],
        ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()],
        ['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()],
    )->create(['user_id' => $top->id, 'vehicle_id' => $this->anh->id]);
    Booking::factory()->cancelled()->create(['user_id' => $this->member->id, 'vehicle_id' => $this->mtw->id]);

    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(TopBookersWidget::class)
        ->assertCanSeeTableRecords([$top, $this->member], inOrder: true)
        ->assertTableColumnStateSet('bookings_count', 3, $top->getKey())
        ->assertTableColumnStateSet('bookings_count', 1, $this->member->getKey());

    Livewire::test(VehicleBookingsWidget::class)
        ->assertCanSeeTableRecords([$this->anh, $this->mtw], inOrder: true)
        ->assertTableColumnStateSet('bookings_count', 1, $this->mtw->getKey());

    $this->get('/admin/fahrzeugbuchung')->assertOk()->assertSee('Fahrzeug-Monitor');
    Livewire::test(MonitorLinkWidget::class)
        ->assertSee(route('fahrzeugbuchung.monitor', ['key' => 'geheimer-wachen-schluessel']));
});
