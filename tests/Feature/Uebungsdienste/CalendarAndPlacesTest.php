<?php

use App\Livewire\Catalog\PlacesAndDress;
use App\Livewire\Dashboard\Home;
use App\Models\DressCode;
use App\Models\Group;
use App\Models\Module;
use App\Models\Place;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\EventDetail;
use Modules\Kalender\src\Livewire\EventForm;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Template;
use Modules\Kalender\src\Notifications\EventCreatedNotification;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Services\IcsExporter;
use Modules\Uebungsdienste\src\Livewire\ServiceForm;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\ServicePlan;

beforeEach(function () {
    Module::whereIn('key', ['uebungsdienste', 'kalender'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-03-10 12:00', 'Europe/Berlin'));

    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->manager = User::factory()->create(['name' => 'Tina Terminverwalter']);
    $this->manager->moduleAccess()->create(['module' => 'kalender', 'role' => 'terminverwalter']);
    $this->youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    foreach ([$this->hanna, $this->bernd] as $u) {
        $u->moduleAccess()->create(['module' => 'kalender']);
    }
    $this->hanna->groups()->attach($this->youth);

    $this->station = Place::create(['name' => 'Gerätehaus Musterstadt', 'address' => 'Hauptstraße 1, 12345 Musterstadt', 'sort_order' => 1]);
    $this->turnout = DressCode::create(['name' => 'Einsatzkleidung', 'sort_order' => 1]);
    $this->plan = app(ServicePlan::class);
});

test('Orte and Kleidung are one list: kept in the Kalender, they are there in the Übungsdienstplaner too', function () {
    Livewire::actingAs($this->manager)->test(PlacesAndDress::class, ['ability' => 'manage', 'argument' => Event::class])
        ->set('place.name', 'Sportplatz')->set('place.address', 'Am Sportplatz 3, 12345 Musterstadt')->call('savePlace')->assertSee('Ort gespeichert')
        ->set('place.name', 'gerätehaus musterstadt')->call('savePlace')->assertHasErrors('place')
        ->set('dress', 'Sportkleidung')->call('saveDress')->assertSee('Kleidung gespeichert')
        ->call('move', 'dress', DressCode::where('name', 'Sportkleidung')->value('id'), -1);

    expect(DressCode::options()->values()->all())->toBe(['Sportkleidung', 'Einsatzkleidung']);
    $this->actingAs($this->admin)->get(route('uebungsdienste.places'))->assertOk()->assertSee('Sportplatz')->assertSee('Sportkleidung');
    $this->actingAs($this->manager)->get(route('kalender.places'))->assertOk();
    $this->actingAs($this->hanna)->get(route('kalender.places'))->assertForbidden();
    Livewire::actingAs($this->hanna)->test(PlacesAndDress::class, ['ability' => 'plan-uebungsdienste'])->assertForbidden();
});

test('an Übungsdienst is a Termin in the Kalender of its target group – with Ort and Kleidung, never with the topic', function () {
    Livewire::actingAs($this->admin)->test(ServiceForm::class)
        ->assertSet('placeId', (string) $this->station->id)->assertSet('location', 'Gerätehaus Musterstadt')
        ->set('date', '2027-03-16')->set('time', '19:00')->set('endTime', '21:30')
        ->set('dressCodeIds', [(string) $this->turnout->id])->set('groupIds', [(string) $this->youth->id])
        ->call('save')->assertRedirect();

    $service = Service::sole();
    $this->plan->setTopic($service, 'Geheimes Übungsszenario Bahnunfall');
    $event = Event::sole();
    expect($event)->title->toBe('Übungsdienst')->type->toBe('uebung')->description->toBeNull()->managed_by->toBe('uebungsdienste')
        ->location->toBe('Gerätehaus Musterstadt')->place_id->toBe($this->station->id)->dress_code_ids->toBe([$this->turnout->id])
        ->and($event->starts_at->copy()->setTimezone('Europe/Berlin')->format('d.m. H:i'))->toBe('16.03. 19:00')
        ->and($event->ends_at->copy()->setTimezone('Europe/Berlin')->format('H:i'))->toBe('21:30')
        ->and($service->fresh()->event_id)->toBe($event->id)
        ->and(Event::visibleTo($this->hanna)->count())->toBe(1)->and(Event::visibleTo($this->bernd)->count())->toBe(0);

    Livewire::actingAs($this->hanna)->test(Home::class)->assertSee('Übungsdienst')->assertSee('Hauptstraße 1, 12345 Musterstadt')
        ->assertSee('Einsatzkleidung')->assertSee('google.com/maps/dir', false)->assertDontSee('Bahnunfall');
    $this->actingAs($this->hanna)->get(route('kalender.show', $event))->assertOk()->assertSee('Kommt aus dem Übungsdienstplaner')->assertDontSee('Bahnunfall');
    expect(app(IcsExporter::class)->render(collect([$event->fresh()])))->not->toContain('Bahnunfall')->toContain('Hauptstraße 1');

    // Moving the Dienst moves the Termin; deleting it removes the Termin
    $this->plan->reschedule($this->admin, $service->fresh(), '2027-03-17', '18:30', null);
    expect($event->fresh()->starts_at->copy()->setTimezone('Europe/Berlin')->format('d.m. H:i'))->toBe('17.03. 18:30')
        ->and($event->fresh()->dress_code_ids)->toBe([$this->turnout->id]);
    $this->plan->delete($this->admin, $service->fresh());
    expect(Event::count())->toBe(0);

    expect($this->plan->addSeries($this->admin, '2027-04-06', '2027-05-04', 2, '19:00', 'Wache'))->toHaveCount(3)
        ->and(Event::where('managed_by', 'uebungsdienste')->count())->toBe(3);
});

test('the hourly sync brings Dienste into a Kalender switched on later; one deleted there stays deleted', function () {
    Module::where('key', 'kalender')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $first = $this->plan->add($this->admin, '2027-03-16', '19:00', 'Wache');
    $second = $this->plan->add($this->admin, '2027-03-30', '', 'Wache');
    expect(Event::count())->toBe(0);

    Module::where('key', 'kalender')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->artisan('uebungsdienste:sync-calendar')->assertSuccessful();
    expect(Event::count())->toBe(2)->and(Event::where('all_day', true)->sole()->id)->toBe($second->fresh()->event_id);

    app(EventService::class)->delete(Event::findOrFail($first->fresh()->event_id));
    $this->artisan('uebungsdienste:sync-calendar')->assertSuccessful();
    expect(Event::count())->toBe(1);
    $this->plan->reschedule($this->admin, $first->fresh(), '2027-03-16', '19:30', 'Wache');
    expect(Event::count())->toBe(2);
});

test('a Kalender Termin takes a saved place and Kleidung; they are shown, mailed, kept in templates and in the feed', function () {
    Livewire::actingAs($this->manager)->test(EventForm::class)
        ->set('title', 'Arbeitsdienst')->set('type', 'arbeitsdienst')->set('startsAt', '2027-03-20T09:00')->set('endsAt', '2027-03-20T12:00')
        ->set('placeId', (string) $this->station->id)->assertSet('location', 'Gerätehaus Musterstadt')
        ->set('dressCodeIds', [(string) $this->turnout->id])->set('notify', true)
        ->set('templateName', 'Arbeitsdienst Samstag')->call('saveTemplate')
        ->call('save')->assertHasNoErrors();

    $event = Event::sole();
    expect($event)->place_id->toBe($this->station->id)->dress_code_ids->toBe([$this->turnout->id])
        ->and(Template::sole()->data)->toMatchArray(['place_id' => $this->station->id, 'dress_code_ids' => [$this->turnout->id]]);
    Notification::assertSentTo($this->hanna, EventCreatedNotification::class, function ($n, $c, $u) {
        $lines = implode(' ', $n->toMail($u)->introLines);

        return str_contains($lines, 'Ort: Gerätehaus Musterstadt, Hauptstraße 1') && str_contains($lines, 'Kleidung: Einsatzkleidung');
    });
    $this->actingAs($this->hanna)->get(route('kalender.show', $event))->assertSee('Hauptstraße 1, 12345 Musterstadt')->assertSee('Kleidung:')->assertSee('Navigation');

    // A deleted place leaves the name as Ort; a free Ort that is a place's name gets the navigation too
    $this->station->delete();
    expect($event->fresh())->place_id->toBeNull()->location->toBe('Gerätehaus Musterstadt');
    Place::create(['name' => 'Gerätehaus Musterstadt', 'address' => 'Neue Straße 2']);
    $this->actingAs($this->hanna)->get(route('kalender.show', $event))->assertSee('Neue Straße 2');
});

test('an Übungsdienst asks for a Rückmeldung with a Frist, mails the Kameraden on request, and the responsible people are zugesagt', function () {
    Livewire::actingAs($this->admin)->test(ServiceForm::class)
        ->set('date', '2027-03-16')->set('time', '19:00')->set('endTime', '21:00')->set('groupIds', [(string) $this->youth->id])
        ->set('rsvp', true)->set('rsvpDays', '2')->set('notify', true)->call('save')->assertRedirect();
    $service = Service::sole();
    $event = Event::findOrFail($service->event_id);
    expect($event->rsvp)->toBeTrue()
        ->and($event->rsvp_deadline->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('14.03.2027 23:59');
    Notification::assertSentTo($this->hanna, EventCreatedNotification::class);
    Notification::assertNotSentTo($this->bernd, EventCreatedNotification::class);
    // Who planned it is not on it
    expect($event->responses()->count())->toBe(0);

    // Made responsible: zugesagt in the Termin, even outside the target group, and can still change it
    $this->plan->addResponsible($service, $this->bernd, $this->admin);
    expect($event->responses()->where('user_id', $this->bernd->id)->value('status'))->toBe('yes');
    app(EventService::class)->respond($event, $this->bernd, 'maybe');
    expect($event->responses()->where('user_id', $this->bernd->id)->value('status'))->toBe('maybe');

    // A Kamerad of the target group answers, sees when, and may change it until the start – after the Frist too
    app(EventService::class)->respond($event, $this->hanna, 'yes');
    $this->travelTo(Carbon::parse('2027-03-15 10:00', 'Europe/Berlin'));
    app(EventService::class)->respond($event, $this->hanna, 'no');
    Livewire::actingAs($this->hanna)->test(EventDetail::class, ['event' => $event])
        ->assertSee('Abgesagt</strong>', false)->assertSee('am 15.03.2027 um 10:00 Uhr (nach der Frist)');

    // Without e-mail: the Kameraden are not told
    Notification::fake();
    $this->plan->add($this->admin, '2027-03-23', '19:00', 'Wache', ['group_ids' => [$this->youth->id], 'rsvp' => true, 'notify' => false]);
    Notification::assertNothingSent();
});
