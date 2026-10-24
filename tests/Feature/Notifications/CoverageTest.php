<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Notifications\BookingChangedByOtherNotification;
use Modules\Fahrzeugbuchung\src\Services\BookingService;
use Modules\Kalender\src\Notifications\EventCancelledNotification;
use Modules\Kalender\src\Notifications\EventChangedNotification;
use Modules\Kalender\src\Services\EventService;
use Modules\Mitteilungen\src\Notifications\AnnouncementNotification;
use Modules\Mitteilungen\src\Services\AnnouncementService;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Notifications\ShiftsChangedNotification;
use Modules\Schichtplan\src\Services\ChangeNotifier;
use Modules\Schichtplan\src\Services\PlanMailer;
use Modules\Schichtplan\src\Services\PlanService;
use Modules\Schichtplan\src\Services\SignupService;
use Modules\Uebungsdienste\src\Notifications\ResponsibleNotification;
use Modules\Uebungsdienste\src\Services\ServicePlan;

beforeEach(function () {
    Module::whereIn('key', ['schichtplan', 'kalender', 'mitteilungen', 'uebungsdienste', 'fahrzeugbuchung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-06-01 10:00', 'Europe/Berlin'));

    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    foreach (['schichtplan', 'kalender'] as $module) {
        $this->hanna->moduleAccess()->create(['module' => $module]);
        $this->bernd->moduleAccess()->create(['module' => $module]);
    }
});

/** A published planer plan: Hanna at the Grill 14–15, Bernd at the Kasse 14–15 */
function publishedPlan()
{
    $t = test();
    $plans = app(PlanService::class);
    $start = Carbon::parse('2027-06-12 14:00', 'Europe/Berlin');
    $plan = $plans->create(['title' => 'Dorffest', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2), 'shift_minutes' => 60, 'max_consecutive' => 2], $t->admin);
    $t->grill = $plans->addStation($plan, 'Grill', 1);
    $t->kasse = $plans->addStation($plan, 'Kasse', 1);
    $plans->addParticipants($plan, [$t->hanna->id, $t->bernd->id]);
    $t->slot = $plan->slots()->first();
    $plans->setSeat($plan, $t->slot, $t->grill, null, $t->hanna->id);
    $plans->setSeat($plan, $t->slot, $t->kasse, null, $t->bernd->id);
    app(PlanMailer::class)->publish($plan->refresh());

    return $plan->refresh();
}

test('the published Schichtplan shows the own shifts under the bell and links to the plan', function () {
    $plan = publishedPlan();

    $entry = $this->hanna->notifications()->sole()->data;
    expect($entry['title'])->toBe('Schichtplan: Dorffest')
        ->and($entry['body'])->toBe('Deine Schichten: 14:00–15:00 Grill')
        ->and($entry['url'])->toBe(route('schichtplan.show', $plan));
});

test('Mitteilungen reach only Kameraden with access to the module', function () {
    Module::where('key', 'mitteilungen')->update(['auto_assign' => false]);
    Cache::flush();
    Notification::fake();
    $this->hanna->moduleAccess()->create(['module' => 'mitteilungen']);

    $sent = app(AnnouncementService::class)->mail(app(AnnouncementService::class)->save(null, ['title' => 'Grillabend', 'body' => 'Samstag'], [], $this->admin));

    Notification::assertSentTo([$this->hanna, $this->admin], AnnouncementNotification::class);
    Notification::assertNotSentTo($this->bernd, AnnouncementNotification::class);
    expect($sent)->toBe(2);
});

test('a moved Termin reaches who said yes or maybe; "per E-Mail" everyone; taking place again too', function () {
    Notification::fake();
    $events = app(EventService::class);
    $otto = User::factory()->create(['name' => 'Otto Ortsbrand']);
    $otto->moduleAccess()->create(['module' => 'kalender']);
    $data = ['title' => 'Übung', 'type' => 'uebung', 'rsvp' => true, 'location' => 'Gerätehaus',
        'starts_at' => Carbon::parse('2027-06-10 19:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-06-10 21:00', 'Europe/Berlin')];
    $event = $events->create($data, [], $this->admin)->first();
    $events->respond($event, $this->hanna, 'yes');
    $events->respond($event, $this->bernd, 'no');

    $events->update($event, [...$data, 'description' => 'Bitte pünktlich'], [], by: $this->admin);
    Notification::assertNothingSent();

    $events->update($event, [...$data, 'starts_at' => $data['starts_at']->copy()->addHour(), 'location' => 'Sportplatz'], [], by: $this->admin);
    Notification::assertSentTo($this->hanna, EventChangedNotification::class, function ($n, $c, $u) {
        $lines = implode(' ', $n->toMail($u)->introLines);

        return str_contains($lines, '20:00') && str_contains($lines, 'Bisher:') && str_contains($lines, 'Ort bisher: Gerätehaus');
    });
    Notification::assertNotSentTo([$this->bernd, $otto], EventChangedNotification::class);

    Notification::fake();
    $events->update($event->refresh(), [...$data, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'location' => 'Sportplatz'], [], by: $this->admin, notify: true);
    Notification::assertSentTo([$this->hanna, $this->bernd, $otto], EventChangedNotification::class);

    Notification::fake();
    $events->cancel($event, $this->admin);
    Notification::assertSentTo($this->hanna, EventCancelledNotification::class, fn ($n, $c, $u) => $n->toMail($u)->actionUrl === route('kalender.show', $event));
    $events->reinstate($event, $this->admin);
    Notification::assertSentTo($this->hanna, EventChangedNotification::class, fn ($n, $c, $u) => str_contains($n->toMail($u)->subject, 'Findet doch statt'));
    Notification::assertNotSentTo($this->bernd, EventChangedNotification::class);
});

test('a booking changed by an admin or Entscheider reaches its owner, own changes stay quiet', function () {
    Notification::fake();
    $vehicle = Vehicle::factory()->create(['name' => 'MTW']);
    $service = app(BookingService::class);
    $data = ['vehicle_id' => $vehicle->id, 'purpose' => 'Jugendfeuerwehr',
        'starts_at' => Carbon::parse('2027-06-05 09:00', 'Europe/Berlin')->utc(), 'ends_at' => Carbon::parse('2027-06-05 12:00', 'Europe/Berlin')->utc()];

    $this->actingAs($this->hanna);
    $own = $service->create($data, $this->hanna->id);
    $service->update($own, ['purpose' => 'Ausflug']);
    $service->cancel($own);
    Notification::assertNothingSent();

    $this->actingAs($this->admin);
    $booking = $service->create($data, $this->hanna->id);
    Notification::assertSentTo($this->hanna, BookingChangedByOtherNotification::class, fn ($n, $c, $u) => $n->toMail($u)->subject === 'Für dich gebucht: MTW am 5. Jun 2027');

    Notification::fake();
    $service->update($booking, ['ends_at' => Carbon::parse('2027-06-05 13:00', 'Europe/Berlin')->utc()]);
    Notification::assertSentTo($this->hanna, BookingChangedByOtherNotification::class, function ($n, $c, $u) {
        $mail = $n->toMail($u);

        return str_starts_with($mail->subject, 'Buchung geändert') && str_contains(implode(' ', $mail->introLines), '**Bisher:** MTW, Sa., 5. Jun 2027, 09:00 – 12:00 Uhr');
    });

    Notification::fake();
    $service->cancel($booking);
    Notification::assertSentTo($this->hanna, BookingChangedByOtherNotification::class, fn ($n, $c, $u) => str_starts_with($n->toMail($u)->subject, 'Buchung storniert'));
    expect($this->hanna->notifications()->count())->toBe(0);
});

test('whoever is made responsible for a coming Übungsdienst hears about it', function () {
    Notification::fake();
    $board = User::factory()->create(['name' => 'Vera Vorstand']);
    $board->moduleAccess()->create(['module' => 'uebungsdienste', 'role' => 'übungsdienstplaner']);
    $plan = app(ServicePlan::class);
    $service = $plan->add($this->admin, '2027-06-08', '19:00', 'Gerätehaus');
    $plan->setTopic($service, 'Knoten und Stiche');

    $plan->addResponsible($service, $this->hanna, $board);
    $plan->addResponsible($service, $this->hanna, $board);
    $plan->addResponsible($service, $board, $board);

    Notification::assertSentToTimes($this->hanna, ResponsibleNotification::class, 1);
    Notification::assertSentTo($this->hanna, ResponsibleNotification::class, function ($n, $c, $u) {
        $mail = $n->toMail($u);

        return $mail->actionUrl === null && str_contains(implode(' ', $mail->introLines), 'Vera Vorstand hat dich für den Übungsdienst am Di, 08.06.2027, 19:00 Uhr, Gerätehaus als verantwortlich eingetragen.')
            && $n->toDatabase($u)['url'] === route('home');
    });
    Notification::assertNotSentTo($board, ResponsibleNotification::class);

    $old = $plan->add($this->admin, '2027-05-04', '19:00', null);
    $plan->addResponsible($old, $this->bernd, $board);
    Notification::assertNotSentTo($this->bernd, ResponsibleNotification::class);
});

test('shifts changed after publishing reach the people affected once the planer has stopped', function () {
    $plan = publishedPlan();
    Notification::fake();
    $plans = app(PlanService::class);
    $changes = app(ChangeNotifier::class);

    // Hanna and Bernd swap; Bernd is then taken off the plan
    $plans->setSeat($plan, $this->slot, $this->kasse, $this->bernd->id, $this->hanna->id);
    expect($changes->run())->toBe(0);
    $this->travel(6)->minutes();
    $plans->removeParticipant($plan, $this->bernd->id);
    expect($changes->run())->toBe(0);
    $this->travel(6)->minutes();
    expect($changes->run())->toBe(0);
    $this->travel(5)->minutes();
    $this->artisan('schichtplan:notify-changes')->assertSuccessful();

    Notification::assertSentTo($this->hanna, ShiftsChangedNotification::class, fn ($n, $c, $u) => str_contains(implode(' ', $n->toMail($u)->introLines), '12.06. 14:00–15:00 Uhr: Kasse'));
    Notification::assertSentTo($this->bernd, ShiftsChangedNotification::class, fn ($n, $c, $u) => str_contains(implode(' ', $n->toMail($u)->introLines), 'nicht mehr eingeteilt')
        && $n->toMail($u)->actionUrl === route('schichtplan.index'));

    Notification::fake();
    $this->travel(20)->minutes();
    expect($changes->run())->toBe(0);
    app(PlanMailer::class)->send($plan->refresh());
    $this->travel(20)->minutes();
    $changes->run();
    Notification::assertNotSentTo($this->hanna, ShiftsChangedNotification::class);
});

test('in a self sign-up plan a place given back by someone else is told right away', function () {
    $start = Carbon::parse('2027-06-12 14:00', 'Europe/Berlin');
    $plan = app(PlanService::class)->create(['title' => 'Arbeitsdienst', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2), 'shift_minutes' => 60, 'max_consecutive' => 2, 'signup' => 'self'], $this->admin);
    $station = app(PlanService::class)->addStation($plan, 'Hof', 2);
    app(PlanMailer::class)->publish($plan->refresh());
    $slots = $plan->slots()->get();
    $signups = app(SignupService::class);
    $first = $signups->signUp($this->hanna, $plan, $slots[0], $station);
    $signups->signUp($this->hanna, $plan, $slots[1], $station);
    Notification::fake();

    $signups->withdraw($this->admin, $first);
    Notification::assertSentTo($this->hanna, ShiftsChangedNotification::class, fn ($n, $c, $u) => str_contains(implode(' ', $n->toMail($u)->introLines), 'Anna Admin hat deine Schichten')
        && str_contains(implode(' ', $n->toMail($u)->introLines), '15:00–16:00 Uhr: Hof'));

    Notification::fake();
    $signups->withdraw($this->hanna, Assignment::where('user_id', $this->hanna->id)->sole());
    Notification::assertNothingSent();
});
