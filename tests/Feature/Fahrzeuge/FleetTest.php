<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingForm;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\BookingService;
use Modules\Fahrzeugbuchung\src\Services\DecisionService;
use Modules\Fahrzeuge\src\Livewire\CallSigns;
use Modules\Fahrzeuge\src\Livewire\VehicleDetail;
use Modules\Fahrzeuge\src\Livewire\VehicleForm;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Models\Downtime;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Notifications\DeadlineDigestNotification;
use Modules\Fahrzeuge\src\Notifications\VehicleUnavailableNotification;
use Modules\Fahrzeuge\src\Services\FleetService;

beforeEach(function () {
    Module::whereIn('key', ['fahrzeuge', 'fahrzeugbuchung', 'einsatzbericht'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->wart = User::factory()->create(['name' => 'Frieda Fahrzeugwart']);
    $this->wart->moduleAccess()->create(['module' => 'fahrzeuge', 'role' => 'fahrzeugwart']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    foreach (['fahrzeuge', 'fahrzeugbuchung'] as $module) {
        $this->hanna->moduleAccess()->create(['module' => $module]);
    }

    $this->f = app(FleetService::class);
    $this->mtw = Vehicle::factory()->create(['name' => 'MTW', 'is_active' => true]);
    $this->hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
});

function bookMtw(User $user, string $from, string $to): Booking
{
    return app(BookingService::class)->create(['vehicle_id' => test()->mtw->id, 'starts_at' => Carbon::parse($from, 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse($to, 'Europe/Berlin')->utc(), 'purpose' => 'Jugendfeuerwehr Ausflug'], $user->id);
}

test('a vehicle is created once and is the same in Fahrzeugbuchung and Einsatzbericht', function () {
    Livewire::actingAs($this->wart)->test(VehicleForm::class)
        ->set('form.name', '')->call('save')->assertHasErrors('name')
        ->set('form.name', 'TLF 3000')->set('form.call_sign', 'Florian Musterstadt 1/24-1')->set('form.license_plate', 'mu-fw 112')
        ->set('form.category', 'loesch')->set('form.water_liters', '3000')->set('form.mileage_km', '42150')
        ->set('newFeature', 'Wärmebildkamera, Lichtmast')->call('addFeature')
        ->set('photo', UploadedFile::fake()->image('tlf.jpg', 1200, 800))
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $tlf = Vehicle::where('name', 'TLF 3000')->sole();
    $profile = Profile::where('vehicle_id', $tlf->id)->sole();
    expect(Vehicle::active()->pluck('name'))->toContain('TLF 3000')
        ->and($profile)->license_plate->toBe('MU-FW 112')->features->toBe(['Wärmebildkamera', 'Lichtmast'])->water_liters->toBe(3000)
        ->mileage_on->toDateString()->toBe(now('Europe/Berlin')->toDateString())
        ->and($profile->photo)->not->toBeNull();

    $this->actingAs($this->hanna)->get(route('fahrzeuge.photo', $tlf))->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->actingAs($this->hanna)->get(route('fahrzeuge.create'))->assertForbidden();
});

test('status: bedingt einsatzbereit needs a note; außer Dienst is no longer bookable', function () {
    expect(fn () => $this->f->setStatus($this->wart, $this->mtw, 'limited', ''))->toThrow(ValidationException::class);
    $this->f->setStatus($this->wart, $this->mtw, 'limited', 'Blaulicht hinten links defekt');
    expect($this->f->states(collect([$this->mtw]))->first())->label->toBe('Bedingt einsatzbereit')->detail->toBe('Blaulicht hinten links defekt');

    $this->f->decommission($this->wart, $this->mtw, 'verkauft');
    expect(Vehicle::active()->pluck('id'))->not->toContain($this->mtw->id)
        ->and($this->f->states(collect([$this->mtw->fresh()]))->first()['label'])->toBe('Außer Dienst');
    expect(fn () => $this->f->setStatus($this->hanna, $this->hlf, 'ready', null))->toThrow(ValidationException::class);
});

test('a Werkstatt downtime blocks bookings in the Fahrzeugbuchung and tells who booked', function () {
    $existing = bookMtw($this->hanna, 'tomorrow 10:00', 'tomorrow 14:00');
    $from = now('Europe/Berlin')->format('Y-m-d\TH:i');
    $to = now('Europe/Berlin')->addDays(2)->format('Y-m-d\TH:i');

    Livewire::actingAs($this->wart)->test(VehicleDetail::class, ['vehicle' => $this->mtw])
        ->set('downtime.reason', 'Werkstatt: Bremsen')->set('downtime.starts_at', $from)->set('downtime.ends_at', $to)
        ->call('addDowntime')->assertHasNoErrors()->assertSee('1 bestehende Buchung');

    Notification::assertSentTo($this->hanna, VehicleUnavailableNotification::class);
    expect($existing->fresh()->cancelled_at)->toBeNull()
        ->and(Profile::where('vehicle_id', $this->mtw->id)->value('status'))->toBe('unavailable')
        ->and($this->f->states(collect([$this->mtw]))->first()['label'])->toBe('Nicht verfügbar');

    // Booking, checking and asking a decider are refused; the HLF is offered instead
    expect(fn () => bookMtw($this->hanna, 'tomorrow 15:00', 'tomorrow 16:00'))->toThrow(ValidationException::class, 'Werkstatt: Bremsen');
    $check = app(BookingService::class)->checkAvailability($this->mtw->id, now()->addDay(), now()->addDay()->addHour());
    expect($check)->available->toBeFalse()->blocked->toBe('Werkstatt: Bremsen')->and($check['alternatives']->pluck('id')->all())->toBe([$this->hlf->id]);
    expect(fn () => app(DecisionService::class)->request(['vehicle_id' => $this->mtw->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'purpose' => 'x'], $this->hanna, 'dringend'))
        ->toThrow(ValidationException::class, 'nicht verfügbar');

    Livewire::actingAs($this->hanna)->test(BookingForm::class)
        ->dispatch('open-booking-form', date: null, vehicleId: $this->mtw->id)->set('startsAt', now('Europe/Berlin')->addDay()->format('Y-m-d\TH:i'))->set('endsAt', now('Europe/Berlin')->addDay()->addHour()->format('Y-m-d\TH:i'))
        ->assertSet('blocked', 'Werkstatt: Bremsen')->assertSee('Fahrzeug nicht verfügbar')
        ->set('purpose', 'Einkauf')->call('save')->assertHasErrors('vehicleId');

    // In the calendar as a grey event
    $events = $this->actingAs($this->hanna)->getJson(route('fahrzeugbuchung.bookings.events', ['start' => now()->toIso8601String(), 'end' => now()->addDays(3)->toIso8601String()]))->json();
    expect(collect($events)->firstWhere('extendedProps.blocked', true)['title'])->toBe($this->mtw->displayName().' – nicht verfügbar: Werkstatt: Bremsen');

    // Back from the Werkstatt: bookable again
    Livewire::actingAs($this->wart)->test(VehicleDetail::class, ['vehicle' => $this->mtw])
        ->call('endDowntime', Downtime::sole()->id)->assertSet('status', 'ready');
    expect(bookMtw($this->hanna, 'tomorrow 15:00', 'tomorrow 16:00'))->toBeInstanceOf(Booking::class);
});

test('changes inside a downtime: ending early works, moving into it does not; without the module nothing blocks', function () {
    // A fixed midday: between 23:00 and midnight the booking ended "now" would overlap the one from 23:00
    $this->travelTo(Carbon::parse('2027-05-10 12:00', 'Europe/Berlin'));
    $booking = bookMtw($this->hanna, 'today 00:00', 'tomorrow 23:00');
    $this->f->addDowntime($this->wart, $this->mtw, now('Europe/Berlin')->addHours(2)->format('Y-m-d\TH:i'), now('Europe/Berlin')->addHours(5)->format('Y-m-d\TH:i'), 'Werkstatt');

    expect(app(BookingService::class)->endEarly($booking)->ends_at->lte(now()))->toBeTrue();
    expect(fn () => app(BookingService::class)->update($booking->fresh(), ['ends_at' => now()->addDays(1)]))->toThrow(ValidationException::class, 'nicht verfügbar');

    Module::where('key', 'fahrzeuge')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    expect(bookMtw($this->hanna, 'today 23:00', 'tomorrow 01:00'))->toBeInstanceOf(Booking::class);
});

test('deadlines: reminder mail once, documented work moves the next date and the Kilometerstand', function () {
    $hu = $this->f->saveDeadline($this->wart, $this->hlf, null, ['title' => 'Hauptuntersuchung (HU)', 'interval_months' => 12, 'due_on' => now('Europe/Berlin')->addDays(10)->toDateString(), 'lead_days' => 30]);
    $this->f->saveDeadline($this->wart, $this->hlf, null, ['title' => 'Pumpenprüfung (DIN 14420)', 'interval_months' => 12, 'due_on' => now('Europe/Berlin')->subDay()->toDateString()]);
    $this->f->saveDeadline($this->wart, $this->hlf, null, ['title' => 'UVV-Prüfung', 'interval_months' => 12, 'due_on' => now('Europe/Berlin')->addMonths(6)->toDateString()]);

    $this->artisan('fahrzeuge:remind')->assertSuccessful();
    Notification::assertSentTo($this->wart, DeadlineDigestNotification::class, function ($n, $c, $u) {
        $text = implode(' ', $n->toMail($u)->introLines);

        return str_contains($text, 'HLF 20 – Pumpenprüfung') && str_contains($text, 'HLF 20 – Hauptuntersuchung') && ! str_contains($text, 'UVV');
    });
    Notification::fake();
    $this->artisan('fahrzeuge:remind')->assertSuccessful();
    Notification::assertNothingSent();

    $record = $this->f->recordWork($this->wart, $this->hlf, ['deadline_id' => $hu->id, 'done_on' => now('Europe/Berlin')->toDateString(), 'mileage_km' => 51200, 'cost_cents' => 18990, 'provider' => 'Prüfstelle Muster'],
        [UploadedFile::fake()->create('bericht.pdf', 20, 'application/pdf')]);
    expect($record)->title->toBe('Hauptuntersuchung (HU)')->kind->toBe('inspection')->cost()->toBe('189,90 €')
        ->and($record->attachments()->sole()->mime)->toBe('application/pdf')
        ->and($hu->fresh()->due_on->toDateString())->toBe(now('Europe/Berlin')->addMonths(12)->toDateString())
        ->and(Profile::where('vehicle_id', $this->hlf->id)->value('mileage_km'))->toBe(51200);
    expect(fn () => $this->f->recordWork($this->wart, $this->hlf, ['kind' => 'repair', 'title' => 'x', 'done_on' => now()->addDays(3)->toDateString()]))
        ->toThrow(ValidationException::class, 'Zukunft');
});

test('who is out at an Einsatz or booked right now, and the Einsatzhistorie', function () {
    $leader = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $reports = app(ReportService::class);
    $report = $reports->create($leader);
    $reports->start($leader, $report);
    $reports->update($leader, $report, ['keyword' => 'FEU 2']);
    $rv = $reports->addVehicle($leader, $report, $this->hlf->id);
    $reports->assign($leader, $rv, $this->hanna, 'gruppenfuehrer');
    $rv->update(['departed_at' => now()]);
    bookMtw($this->hanna, '-1 hour', '+2 hours');

    $states = $this->f->states(collect([$this->hlf, $this->mtw]));
    expect($states[$this->hlf->id])->label->toBe('Im Einsatz')->detail->toBe('FEU 2')
        ->and($states[$this->mtw->id])->label->toBe('Gebucht')->detail->toStartWith('Hanna Hydrant bis');

    $this->actingAs($this->hanna)->get(route('fahrzeuge.index'))->assertOk()->assertSee('Im Einsatz')->assertSee('HLF 20 (1 Pers.');
    $this->actingAs($this->hanna)->get(route('fahrzeuge.show', $this->hlf))->assertOk()->assertSee($report->number)->assertSee('FEU 2');
    expect($this->f->einsatzHistory($this->hlf))->toHaveCount(1);
});

test('the pages open and the start page shows the fleet', function () {
    $this->f->saveVehicle($this->wart, $this->hlf, ['name' => 'HLF 20', 'vehicle_type' => 'HLF 20 (DIN 14530-27)', 'features' => ['Hydraulischer Rettungssatz']]);
    $this->f->saveDeadline($this->wart, $this->hlf, null, ['title' => 'Hauptuntersuchung (HU)', 'interval_months' => 12, 'due_on' => now('Europe/Berlin')->subDay()->toDateString()]);

    $this->actingAs($this->hanna)->get(route('fahrzeuge.index'))->assertOk()->assertSee('HLF 20')->assertSee('1 Frist überfällig');
    $this->actingAs($this->hanna)->get(route('fahrzeuge.show', $this->hlf))->assertOk()->assertSee('Hydraulischer Rettungssatz')->assertDontSee('erledigt eintragen');
    $this->actingAs($this->wart)->get(route('fahrzeuge.show', $this->hlf))->assertOk()->assertSee('erledigt eintragen');
    $this->actingAs($this->wart)->get(route('fahrzeuge.edit', $this->hlf))->assertOk()->assertSee('HLF 20 (DIN 14530-27)');

    Livewire::actingAs($this->wart)->test(Home::class)->assertSee('2 von 2 einsatzbereit')->assertSee('1 Frist überfällig');
    Livewire::actingAs($this->hanna)->test(Home::class)->assertSee('2 von 2 einsatzbereit')->assertDontSee('Frist überfällig');
    expect(Deadline::count())->toBe(1);
});

test('Funkrufnamen at a glance: every vehicle in service with its call sign, searchable', function () {
    Profile::create(['vehicle_id' => $this->hlf->id, 'call_sign' => 'Florian Musterstadt 42/1', 'license_plate' => 'MU-FW 421', 'crew' => '1/8']);
    Profile::create(['vehicle_id' => $this->mtw->id, 'call_sign' => 'Florian Musterstadt 19/1']);
    $old = Vehicle::factory()->create(['name' => 'LF 8 alt', 'is_active' => false]);
    Profile::create(['vehicle_id' => $old->id, 'call_sign' => 'Florian Musterstadt 44/1']);
    $tsf = Vehicle::factory()->create(['name' => 'TSF', 'is_active' => true]);

    $this->actingAs($this->hanna)->get(route('fahrzeuge.call-signs'))->assertOk()->assertSee('Funkrufnamen');
    Livewire::actingAs($this->hanna)->test(CallSigns::class)
        ->assertSeeInOrder(['Florian Musterstadt 19/1', 'MTW', 'Florian Musterstadt 42/1', 'HLF 20', 'MU-FW 421'])
        ->assertDontSee('Florian Musterstadt 44/1')
        ->assertSee('Ohne Funkrufnamen: TSF')
        ->set('search', '42')->assertSee('HLF 20')->assertDontSee('Florian Musterstadt 19/1');
});
