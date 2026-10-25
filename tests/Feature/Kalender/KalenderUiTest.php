<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\Place;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\CalendarView;
use Modules\Kalender\src\Livewire\EventDetail;
use Modules\Kalender\src\Livewire\EventForm;
use Modules\Kalender\src\Livewire\Sources;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    $this->manager = kalenderUser('terminverwalter', ['name' => 'Bernd Blaulicht']);
    $this->member = kalenderUser(attributes: ['name' => 'Hanna Hydrant']);
});

test('the module is hidden while inactive and needs a grant', function () {
    $this->actingAs($this->member)->get('/kalender')->assertOk()->assertSee('Die nächsten Termine');
    $this->actingAs(User::factory()->create())->get('/kalender')->assertForbidden();

    Module::where('key', 'kalender')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->member)->get('/kalender')->assertNotFound();
});

test('only Terminverwalter create events and manage external calendars', function () {
    $this->actingAs($this->member)->get('/kalender/termine/neu')->assertForbidden();
    $this->actingAs($this->member)->get('/kalender/externe-kalender')->assertForbidden();
    $this->actingAs($this->manager)->get('/kalender/termine/neu')->assertOk();
    $this->actingAs($this->manager)->get('/kalender/externe-kalender')->assertOk();
});

test('a Terminverwalter creates a repeating event with Rückmeldung', function () {
    Livewire::actingAs($this->manager)->test(EventForm::class)
        ->set('title', 'Übungsdienst')
        ->set('type', 'uebung')
        ->set('startsAt', '2027-06-08T19:00')
        ->set('endsAt', '2027-06-08T21:30')
        ->set('location', 'Gerätehaus')
        ->set('rsvp', true)
        ->set('rsvpUntil', '2027-06-07T23:59')
        ->set('repeat', 'biweekly')
        ->set('repeatUntil', '2027-07-06')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $events = Event::orderBy('starts_at')->get();
    expect($events)->toHaveCount(3)
        ->and($events[0]->timeLabel())->toBe('Di, 08.06.2027 · 19:00–21:30 Uhr')
        ->and($events[0]->rsvp_deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('07.06.2027 23:59')
        ->and($events[2]->rsvp_deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y'))->toBe('05.07.2027');
});

test('a whole-day event over several days', function () {
    Livewire::actingAs($this->manager)->test(EventForm::class)
        ->set('title', 'Zeltlager')->set('type', 'veranstaltung')->set('allDay', true)
        ->set('startDate', '2027-07-09')->set('endDate', '2027-07-11')
        ->call('save')->assertHasNoErrors();

    expect(Event::sole()->timeLabel())->toBe('Fr, 09.07.2027 – So, 11.07.2027 (ganztägig)');
});

test('an invalid date and an end before the start are refused', function () {
    Livewire::actingAs($this->manager)->test(EventForm::class)
        ->set('title', 'X')->set('startsAt', '08.06.2027 19:00')->call('save')->assertHasErrors('startsAt')
        ->set('startsAt', '2027-06-08T19:00')->set('endsAt', '2027-06-08T18:00')
        ->call('save')->assertHasErrors('endsAt');
});

test('a new event lasts 60 minutes and moving the start keeps the duration', function () {
    Livewire::actingAs($this->manager)->test(EventForm::class)
        ->assertSet('startsAt', '2027-06-02T19:00')
        ->assertSet('endsAt', '2027-06-02T20:00')
        ->set('startsAt', '2027-06-10T18:30')
        ->assertSet('endsAt', '2027-06-10T19:30')
        ->set('endsAt', '2027-06-10T21:00')
        ->set('startsAt', '2027-06-11T18:30')
        ->assertSet('endsAt', '2027-06-11T21:00')
        ->set('allDay', true)
        ->assertSet('startDate', '2027-06-11');
});

test('members answer on the event page and see who comes', function () {
    $event = kalenderEvent('2027-06-15 19:00', attributes: ['rsvp' => true]);

    Livewire::actingAs($this->member)->test(EventDetail::class, ['event' => $event])
        ->set('comment', 'bringe Kuchen mit')
        ->call('respond', 'yes')
        ->assertHasNoErrors()
        ->assertSee('Zugesagt</strong>', false)->assertSee('am 01.06.2027 um 12:00 Uhr')->assertSee('Ändern geht bis zum Beginn')
        ->assertSee('1 zugesagt');

    expect($event->responses()->sole()->comment)->toBe('bringe Kuchen mit');

    // The Terminverwalter also sees who has not answered
    Livewire::actingAs($this->manager)->test(EventDetail::class, ['event' => $event])->assertSee('Noch keine Antwort');
});

test('the Terminverwalter cancels and deletes from the event page', function () {
    $event = kalenderEvent('2027-06-15 19:00');

    Livewire::actingAs($this->manager)->test(EventDetail::class, ['event' => $event])
        ->call('cancelEvent')->assertSee('Abgesagt')
        ->call('reinstate')->assertDontSee('Doch stattfinden lassen');

    Livewire::actingAs($this->member)->test(EventDetail::class, ['event' => $event])->call('cancelEvent')->assertForbidden();

    Livewire::actingAs($this->manager)->test(EventDetail::class, ['event' => $event])->call('deleteEvent', false)->assertRedirect(route('kalender.index'));
    expect(Event::count())->toBe(0);
});

test('the month view shows the events of the month and filters by type', function () {
    kalenderEvent('2027-06-15 19:00', attributes: ['title' => 'Atemschutzübung']);
    kalenderEvent('2027-06-20 10:00', '14:00', ['title' => 'Sommerfest', 'type' => 'veranstaltung']);
    kalenderEvent('2027-08-01 10:00', '12:00', ['title' => 'Im August']);

    Livewire::actingAs($this->member)->withQueryParams(['ansicht' => 'monat'])->test(CalendarView::class)
        ->assertSee('Juni 2027')->assertSee('Atemschutzübung')->assertSee('Sommerfest')
        ->set('type', 'veranstaltung')->assertDontSee('Atemschutzübung')->assertSee('Sommerfest')
        ->set('type', '')->call('shift', 2)->assertSee('August 2027')->assertSee('Im August');
});

test('the next event appears on the start page', function () {
    kalenderEvent('2027-06-15 19:00', attributes: ['title' => 'Übungsdienst Brandschutz', 'rsvp' => true]);
    kalenderEvent('2027-06-25 19:00', attributes: ['title' => 'Später']);

    Livewire::actingAs($this->member)->test(Home::class)
        ->assertSee('Nächster Termin')->assertSee('Übungsdienst Brandschutz')->assertSee('Bitte Rückmeldung geben')
        ->assertDontSee('Später');
});

test('the next event card keeps the Navigation link inside the card, never a link in a link', function () {
    $place = Place::create(['name' => 'Wache', 'address' => 'Hauptstraße 1, 12345 Musterstadt']);
    kalenderEvent('2027-06-15 19:00', attributes: ['title' => 'Übungsdienst Brandschutz', 'place_id' => $place->id, 'location' => 'Wache']);

    $card = Str::between(Livewire::actingAs($this->member)->test(Home::class)->html(), 'Nächster Termin', 'Kleidung');
    // The title links to the Termin; an <a> opened before it is closed would be cut off by the browser
    expect($card)->toContain('Navigation')->toContain('Übungsdienst Brandschutz</a>')
        ->and(preg_match('/<a [^>]*>(?:(?!<\/a>).)*<a /s', $card))->toBe(0);
});

test('an external calendar is added and pulled in at once', function () {
    Http::fake(['*' => Http::response("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:1\r\nSUMMARY:Kreisfeuerwehrtag\r\nDTSTART:20270620T080000Z\r\nDTEND:20270620T150000Z\r\nEND:VEVENT\r\nEND:VCALENDAR")]);

    Livewire::actingAs($this->manager)->test(Sources::class)
        ->set('name', 'KFV')->set('url', 'https://kfv.example.com/k.ics')->set('type', 'veranstaltung')
        ->call('add')
        ->assertSee('1 Termine aus „KFV“ übernommen.', false);

    $this->actingAs($this->member)->get('/kalender?monat=2027-06')->assertSee('Kreisfeuerwehrtag');
});

test('the subscription link is shown on request', function () {
    Livewire::actingAs($this->member)->test(CalendarView::class)
        ->call('toggleFeed')
        ->assertSee('/kalender/abo/');
});

test('week and list view, and the start view comes from the profile setting', function () {
    kalenderEvent('2027-06-02 19:00', attributes: ['title' => 'Diese Woche']);
    kalenderEvent('2027-06-09 19:00', attributes: ['title' => 'Nächste Woche']);
    kalenderEvent('2027-06-25 19:00', attributes: ['title' => 'Ende Juni']);

    // Profile "Woche" (also the default) → week view; the month grid has always whole weeks
    $titles = fn ($component) => collect($component->viewData('days'))->flatMap(fn ($d) => $d['events']->pluck('title'))->unique()->values()->all();

    $week = Livewire::actingAs($this->member)->test(CalendarView::class)
        ->assertSet('view', 'woche')
        ->assertSee('KW 22');
    expect(count($week->viewData('days')))->toBe(7)
        ->and($titles($week))->toBe(['Diese Woche']);

    $week->call('shift', 1);
    expect($titles($week))->toBe(['Nächste Woche']);

    $this->member->update(['calendar_view' => 'listWeek']);
    Livewire::actingAs($this->member)->test(CalendarView::class)
        ->assertSet('view', 'liste')
        ->assertSee('Diese Woche')->assertSee('Nächste Woche')->assertSee('Ende Juni')
        ->assertDontSee('Die nächsten Termine');

    $this->member->update(['calendar_view' => 'dayGridMonth']);
    $month = Livewire::actingAs($this->member)->test(CalendarView::class)->assertSet('view', 'monat');
    expect(count($month->viewData('days')) % 7)->toBe(0);

    $month->call('setView', 'liste')->assertSet('view', 'liste');
});

test('the next Termin is on the start page also for Kameraden without access to the Kalender', function () {
    Module::where('key', 'kalender')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $creator = User::factory()->admin()->create();
    $kamerad = User::factory()->create(['name' => 'Tim Tanklösch']);
    app(EventService::class)->create(['title' => 'Jahreshauptversammlung', 'type' => 'veranstaltung',
        'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHours(2)], [], $creator);

    Livewire::actingAs($kamerad)->test(Home::class)
        ->assertSee('Nächster Termin')->assertSee('Jahreshauptversammlung')->assertDontSee('kalender/termine/', false);
});

test('a new Termin asks for a Rückmeldung by default; an existing one keeps its setting', function () {
    Livewire::actingAs($this->manager)->test(EventForm::class)->assertSet('rsvp', true);
    $event = kalenderEvent('2027-06-15 19:00', attributes: ['rsvp' => false]);
    Livewire::actingAs($this->manager)->test(EventForm::class, ['event' => $event])->assertSet('rsvp', false);
});

test('the Frist is preset one day before the start, moves with it until set by hand, and takes a time', function () {
    $form = Livewire::actingAs($this->manager)->test(EventForm::class)
        ->set('title', 'Jahreshauptversammlung')->set('type', 'dienst')->set('startsAt', '2027-06-20T19:00')
        ->assertSet('rsvpUntil', '2027-06-19T19:00')->assertSee('Mindestens Kameraden benötigt');
    $form->set('startsAt', '2027-06-21T19:30')->assertSet('rsvpUntil', '2027-06-20T19:30')
        ->set('rsvpUntil', '2027-06-15T18:30')->set('startsAt', '2027-06-22T19:00')->assertSet('rsvpUntil', '2027-06-15T18:30')
        ->call('save')->assertHasNoErrors();
    $event = Event::where('title', 'Jahreshauptversammlung')->sole();
    expect($event->rsvp_deadline->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('15.06.2027 18:30');
    Livewire::actingAs($this->manager)->test(EventForm::class, ['event' => $event])->assertSet('rsvpUntil', '2027-06-15T18:30')
        ->set('rsvpUntil', '')->call('save')->assertHasNoErrors();
    expect($event->fresh()->rsvp_deadline)->toBeNull();
});
