<?php

use App\Models\Group;
use App\Models\Guardian;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Kalender\src\Models\Response;
use Modules\Kalender\src\Services\EventService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    $this->jf = Group::create(['name' => 'Jugendfeuerwehr']);
    $this->member = kalenderUser(null, ['name' => 'Paul Pumpe']);
    $this->member->groups()->attach($this->jf);
});

test('the start page shows open Rückmeldungen with Zusagen/Absagen and the state of the answered ones', function () {
    $open = kalenderEvent('2027-06-05 18:00', '20:00', ['title' => 'Jugenddienst Knoten', 'rsvp' => true], [$this->jf->id]);
    $answered = kalenderEvent('2027-06-12 18:00', '20:00', ['title' => 'Zeltlager Vortreffen', 'rsvp' => true], [$this->jf->id]);
    kalenderEvent('2027-06-06 18:00', '20:00', ['title' => 'Ohne Rückmeldung'], [$this->jf->id]);
    kalenderEvent('2027-06-07 18:00', '20:00', ['title' => 'Nur für andere', 'rsvp' => true], [Group::create(['name' => 'Aktive'])->id]);
    app(EventService::class)->respond($answered, $this->member, 'yes');

    $this->actingAs($this->member)->get('/')->assertOk()
        ->assertSee('data-rsvp-card', false)->assertSee('1 offen')
        ->assertSee('Jugenddienst Knoten')->assertSee(route('kalender.respond', $open), false)
        ->assertSee('Zeltlager Vortreffen')->assertSee('Zugesagt')
        ->assertDontSee('Ohne Rückmeldung')->assertDontSee('Nur für andere');
});

test('Zusagen right from the start page answers and goes back', function () {
    $event = kalenderEvent('2027-06-05 18:00', '20:00', ['title' => 'Jugenddienst', 'rsvp' => true], [$this->jf->id]);

    $this->actingAs($this->member)->from('/')->post(route('kalender.respond', $event), ['status' => 'yes'])->assertRedirect('/');
    expect(Response::where('event_id', $event->id)->where('user_id', $this->member->id)->value('status'))->toBe('yes');

    $this->actingAs($this->member)->get('/')->assertSee('alles beantwortet');

    // Not meant for this Kamerad: refused with a message, nothing stored
    $other = kalenderEvent('2027-06-08 18:00', '20:00', ['rsvp' => true], [Group::create(['name' => 'Aktive'])->id]);
    $this->actingAs($this->member)->from('/')->post(route('kalender.respond', $other), ['status' => 'yes'])
        ->assertRedirect('/')->assertSessionHas('kalender.rsvp_error');
    $this->actingAs($this->member)->post(route('kalender.respond', $event), ['status' => 'kaputt'])->assertStatus(422);
});

test('parents see the open Rückmeldungen of the child in the Eltern-Bereich and on the start page card', function () {
    kalenderEvent('2027-06-05 18:00', '20:00', ['title' => 'Jugenddienst', 'rsvp' => true], [$this->jf->id]);
    kalenderEvent('2027-06-06 18:00', '20:00', ['title' => 'Ausflug', 'rsvp' => true], [$this->jf->id]);
    $sister = kalenderUser(null, ['name' => 'Pia Pumpe']);
    Guardian::create(['user_id' => $this->member->id, 'name' => 'Petra Pumpe', 'email' => 'petra@example.com']);
    Guardian::create(['user_id' => $sister->id, 'name' => 'Petra Pumpe', 'email' => 'petra@example.com']);

    $this->actingAs(Guardian::first(), 'guardian')->get(route('parents.index'))->assertOk()->assertSee('2 Rückmeldungen offen');
});

test('a Kamerad who is a parent sees the open Rückmeldungen of the child on the Eltern card', function () {
    kalenderEvent('2027-06-05 18:00', '20:00', ['title' => 'Jugenddienst', 'rsvp' => true], [$this->jf->id]);
    Guardian::create(['user_id' => $this->member->id, 'name' => 'Petra Pumpe', 'email' => 'petra@example.com']);
    $mum = User::factory()->create(['email' => 'petra@example.com']);

    $this->actingAs($mum)->get('/')->assertOk()->assertSee('data-parent-card', false)->assertSee('1 Rückmeldung offen');
});
