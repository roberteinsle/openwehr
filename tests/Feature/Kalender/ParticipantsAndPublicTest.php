<?php

use App\Models\Group;
use App\Models\Module;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\EventDetail;
use Modules\Kalender\src\Livewire\EventForm;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Notifications\RsvpReminderNotification;
use Modules\Kalender\src\Services\EventService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    $this->events = app(EventService::class);
    $this->manager = kalenderUser('terminverwalter', ['name' => 'Bernd Blaulicht']);
});

// ─── invitations ─────────────────────────────────────────────────────────────

test('individually invited Kameraden see the event and belong to its audience', function () {
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $kid = kalenderUser(attributes: ['name' => 'Kind']);
    $kid->groups()->attach($youth);
    $guest = kalenderUser(attributes: ['name' => 'Jugendwart aus der Einsatzabteilung']);
    $other = kalenderUser(attributes: ['name' => 'Andere']);

    $start = Carbon::parse('2027-06-12 10:00', 'Europe/Berlin');
    $event = $this->events->create(['title' => 'JF-Ausflug', 'type' => 'veranstaltung', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(6)], [$youth->id], null, userIds: [$guest->id])->first();

    expect(Event::visibleTo($guest)->pluck('id')->all())->toBe([$event->id])
        ->and(Event::visibleTo($other)->count())->toBe(0)
        ->and($this->events->audience($event)->pluck('name')->all())->toBe(['Jugendwart aus der Einsatzabteilung', 'Kind']);

    // Only invitations, no Abteilung: just those people
    $meeting = $this->events->create(['title' => 'Führungsbesprechung', 'type' => 'dienst', 'starts_at' => $start->copy()->addDay(), 'ends_at' => $start->copy()->addDay()->addHour()], [], null, userIds: [$guest->id])->first();
    expect($this->events->audience($meeting)->pluck('name')->all())->toBe(['Jugendwart aus der Einsatzabteilung'])
        ->and(Event::visibleTo($kid)->pluck('id')->all())->toBe([$event->id]);
});

test('the form saves invitations, minimum and the public flag', function () {
    $guest = kalenderUser(attributes: ['name' => 'Gast']);

    Livewire::actingAs($this->manager)->test(EventForm::class)
        ->set('title', 'Tag der offenen Tür')->set('type', 'veranstaltung')
        ->set('startsAt', '2027-06-20T11:00')->set('endsAt', '2027-06-20T17:00')
        ->set('inviteeIds', [(string) $guest->id])
        ->set('rsvp', true)->set('minParticipants', '12')
        ->set('isPublic', true)
        ->call('save')->assertHasNoErrors();

    $event = Event::sole();
    expect($event->invitees->pluck('name')->all())->toBe(['Gast'])
        ->and($event->min_participants)->toBe(12)
        ->and($event->is_public)->toBeTrue();
});

// ─── managing participants ───────────────────────────────────────────────────

test('the Terminverwalter answers for others, also after the deadline, and the minimum is tracked', function () {
    [$anna, $ben] = [kalenderUser(attributes: ['name' => 'Anna']), kalenderUser(attributes: ['name' => 'Ben'])];
    $event = kalenderEvent('2027-06-15 19:00', attributes: ['rsvp' => true, 'min_participants' => 2, 'rsvp_deadline' => Carbon::parse('2027-06-01 08:00', 'Europe/Berlin')]);

    // After the Frist the Kamerad still answers; it counts as late
    expect($this->events->respond($event, $anna, 'maybe')->status)->toBe('maybe');

    $detail = Livewire::actingAs($this->manager)->test(EventDetail::class, ['event' => $event])
        ->call('respondFor', $anna->id, 'yes')
        ->assertSee('Es fehlen noch 1 von mindestens 2 Zusagen.');

    expect($event->responses()->where('user_id', $anna->id)->sole())->status->toBe('yes')->set_by->toBe($this->manager->id);

    $detail->call('respondFor', $ben->id, 'yes')->assertSee('Mindestbesetzung von 2 erreicht.')
        ->call('respondFor', $ben->id, '')->assertSee('Es fehlen noch 1');

    Livewire::actingAs($anna)->test(EventDetail::class, ['event' => $event])->call('respondFor', $ben->id, 'yes')->assertForbidden();
});

test('attendance is recorded from the start of the event, also for Kameraden outside the audience', function () {
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $kid = kalenderUser(attributes: ['name' => 'Kind']);
    $kid->groups()->attach($youth);
    $helper = kalenderUser(attributes: ['name' => 'Helfer']);
    $event = kalenderEvent('2027-06-15 19:00', attributes: ['rsvp' => true], groups: [$youth->id]);

    expect(fn () => $this->events->setAttendance($event, $kid, true, $this->manager))->toThrow(ValidationException::class);

    $this->travelTo(Carbon::parse('2027-06-15 19:30', 'Europe/Berlin'));
    $this->events->setAttendance($event, $kid, true, $this->manager);
    $this->events->setAttendance($event, $helper, true, $this->manager);

    expect($this->events->summary($event)['attended']->pluck('user.name')->sort()->values()->all())->toBe(['Helfer', 'Kind']);

    Livewire::actingAs($this->manager)->test(EventDetail::class, ['event' => $event])
        ->assertSee('Anwesend: 2')
        ->call('setAttendance', $kid->id, false)
        ->assertSee('Anwesend: 1');
});

test('the participant list downloads as CSV for the Terminverwalter only', function () {
    $anna = kalenderUser(attributes: ['name' => 'Anna']);
    $event = kalenderEvent('2027-06-15 19:00', attributes: ['rsvp' => true]);
    $this->events->respond($event, $anna, 'yes', 'komme mit Auto');

    $csv = $this->actingAs($this->manager)->get(route('kalender.participants', $event))->assertOk()->getContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")->toContain('Name;Abteilungen;Rückmeldung;Kommentar;Anwesend')->toContain('Anna;;Zugesagt;"komme mit Auto";');
    $this->actingAs($anna)->get(route('kalender.participants', $event))->assertForbidden();
});

// ─── reminders ───────────────────────────────────────────────────────────────

test('Kameraden without an answer are reminded once before the deadline', function () {
    Notification::fake();
    [$answered, $silent] = [kalenderUser(), kalenderUser()];
    $event = kalenderEvent('2027-06-10 19:00', attributes: ['rsvp' => true, 'rsvp_deadline' => Carbon::parse('2027-06-05 23:59', 'Europe/Berlin')]);
    $later = kalenderEvent('2027-06-30 19:00', attributes: ['rsvp' => true]);
    $this->events->respond($event, $answered, 'no');

    expect($this->events->remindPending())->toBe(0); // deadline still more than two days away

    $this->travelTo(Carbon::parse('2027-06-04 09:00', 'Europe/Berlin'));
    expect($this->events->remindPending())->toBeGreaterThanOrEqual(1)
        ->and($this->events->remindPending())->toBe(0);

    Notification::assertSentTo($silent, RsvpReminderNotification::class);
    Notification::assertNotSentTo($answered, RsvpReminderNotification::class);
    expect($later->refresh()->reminder_sent_at)->toBeNull();

    $this->artisan('kalender:remind-responses')->assertSuccessful();
});

// ─── public embedding ────────────────────────────────────────────────────────

test('only public events appear on the embeddable page and feed, without names', function () {
    $anna = kalenderUser(attributes: ['name' => 'Anna Geheim']);
    $open = kalenderEvent('2027-06-20 11:00', '17:00', ['title' => 'Tag der offenen Tür', 'is_public' => true, 'rsvp' => true, 'location' => 'Gerätehaus']);
    kalenderEvent('2027-06-15 19:00', attributes: ['title' => 'Interne Übung']);
    $cancelled = kalenderEvent('2027-06-25 11:00', attributes: ['title' => 'Abgesagtes Fest', 'is_public' => true]);
    $this->events->cancel($cancelled, null);
    $this->events->respond($open, $anna, 'yes');

    $this->get(route('kalender.public'))->assertOk()
        ->assertSee('Tag der offenen Tür')->assertSee('Gerätehaus')
        ->assertDontSee('Interne Übung')->assertDontSee('Abgesagtes Fest')->assertDontSee('Anna Geheim');

    $ics = $this->get(route('kalender.public.ics'))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
    expect($ics)->toContain('SUMMARY:Tag der offenen Tür')->not->toContain('Interne Übung')->not->toContain('/kalender/termine/');
});

test('the public page is gone while the module is off', function () {
    Module::where('key', 'kalender')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');

    $this->get(route('kalender.public'))->assertNotFound();
});

test('the embed code is on the page for external calendars', function () {
    $this->actingAs($this->manager)->get(route('kalender.sources'))
        ->assertSee('Auf der Website der Wehr einbinden')
        ->assertSee(route('kalender.public'));
});
