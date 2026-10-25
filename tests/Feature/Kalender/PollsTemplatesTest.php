<?php

use App\Livewire\Dashboard\Home;
use App\Models\Group;
use App\Models\MemberFunction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\EventForm;
use Modules\Kalender\src\Livewire\PollForm;
use Modules\Kalender\src\Livewire\PollView;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Poll;
use Modules\Kalender\src\Models\Template;
use Modules\Kalender\src\Notifications\EventCreatedNotification;
use Modules\Kalender\src\Notifications\PollCreatedNotification;
use Modules\Kalender\src\Notifications\PollReminderNotification;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Services\PollService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-05-03 10:00', 'Europe/Berlin'));

    $this->manager = kalenderUser('terminverwalter', ['name' => 'Tina Terminverwalter']);
    $this->hanna = kalenderUser(null, ['name' => 'Hanna Hydrant']);
    $this->bernd = kalenderUser(null, ['name' => 'Bernd Blaulicht']);
    $this->otto = kalenderUser(null, ['name' => 'Otto Ortsbrand']);
    $this->youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $this->hanna->groups()->attach($this->youth);
    $this->bernd->groups()->attach($this->youth);
    $this->polls = app(PollService::class);
});

function pollOptions(): array
{
    return collect(['2027-05-15 09:00', '2027-05-16 09:00', '2027-05-22 09:00'])
        ->map(fn ($s) => ['starts_at' => Carbon::parse($s, 'Europe/Berlin'), 'ends_at' => Carbon::parse($s, 'Europe/Berlin')->addHours(4)])->all();
}

test('a Terminabfrage: the target group ticks, the best date shows, fixing it makes an event with the Zusagen', function () {
    Livewire::actingAs($this->manager)->test(PollForm::class)
        ->set('title', 'Arbeitsdienst Frühjahrsputz')->set('type', 'arbeitsdienst')->set('location', 'Gerätehaus')
        ->set('options', [['date' => '2027-05-15', 'from' => '09:00', 'until' => '13:00', 'all_day' => false], ['date' => '2027-05-16', 'from' => '09:00', 'until' => '', 'all_day' => false]])
        ->call('save')->assertHasErrors('options')
        ->set('options.1.until', '13:00')->call('addOption')->assertSet('options.2.date', '2027-05-23')
        ->set('groupIds', [(string) $this->youth->id])->set('deadline', '2027-05-10T20:00')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $poll = Poll::sole();
    expect($poll->options()->count())->toBe(3)->and($poll->type)->toBe('arbeitsdienst');
    Notification::assertSentTo([$this->hanna, $this->bernd], PollCreatedNotification::class);
    Notification::assertNotSentTo($this->otto, PollCreatedNotification::class);
    $this->actingAs($this->otto)->get(route('kalender.polls.show', $poll))->assertForbidden();

    [$sat, $sun, $next] = $poll->options()->pluck('id')->all();
    Livewire::actingAs($this->hanna)->test(PollView::class, ['poll' => $poll])
        ->set("answers.$sat", 'yes')->set("answers.$sun", 'maybe')->set("answers.$next", 'no')->call('save')->assertSee('Antworten sind gespeichert');
    $this->polls->vote($this->bernd, $poll, [$sat => 'maybe', $sun => 'yes']);
    expect(fn () => $this->polls->vote($this->otto, $poll, [$sat => 'yes']))->toThrow(ValidationException::class, 'nicht für dich');

    // Both score 3; the first with most yes wins the tie order but both are "best"
    $results = $this->polls->results($poll->refresh());
    expect($results['best'])->toBe([$sat, $sun])->and($results['open'])->toHaveCount(0);

    Livewire::actingAs($this->manager)->test(PollView::class, ['poll' => $poll])
        ->assertSee('bester Termin')->call('decide', $sat)->assertRedirect();

    $event = Event::sole();
    expect($event)->title->toBe('Arbeitsdienst Frühjahrsputz')->type->toBe('arbeitsdienst')->rsvp->toBeTrue()->location->toBe('Gerätehaus')
        ->and($event->groups()->pluck('groups.id')->all())->toBe([$this->youth->id])
        // Who fixed the date takes part (zugesagt), the answers of the poll are taken over
        ->and($event->responses()->pluck('status', 'user_id')->all())->toEqualCanonicalizing([$this->manager->id => 'yes', $this->hanna->id => 'yes', $this->bernd->id => 'maybe'])
        ->and($poll->refresh())->event_id->toBe($event->id)->isOpen()->toBeFalse();
    Notification::assertSentTo($this->hanna, EventCreatedNotification::class);
    expect(fn () => $this->polls->vote($this->hanna, $poll, [$sat => 'no']))->toThrow(ValidationException::class, 'geschlossen');
});

test('two days before the deadline everyone without an answer is reminded once; members do not create polls', function () {
    $poll = $this->polls->create($this->manager, ['title' => 'Grillabend', 'type' => 'veranstaltung', 'deadline' => Carbon::parse('2027-05-06 20:00', 'Europe/Berlin')],
        pollOptions(), [$this->youth->id], notify: false);
    $this->polls->vote($this->hanna, $poll, [$poll->options()->first()->id => 'yes']);

    $this->travelTo(Carbon::parse('2027-05-05 10:00', 'Europe/Berlin'));
    $this->artisan('kalender:remind-responses')->assertSuccessful();
    Notification::assertSentTo($this->bernd, PollReminderNotification::class);
    Notification::assertNotSentTo($this->hanna, PollReminderNotification::class);
    Notification::fake();
    $this->artisan('kalender:remind-responses')->assertSuccessful();
    Notification::assertNothingSent();

    expect(fn () => $this->polls->create($this->hanna, ['title' => 'x', 'type' => 'uebung'], pollOptions()))->toThrow(ValidationException::class)
        ->and(fn () => $this->polls->create($this->manager, ['title' => 'x', 'type' => 'uebung'], array_slice(pollOptions(), 0, 1)))->toThrow(ValidationException::class, 'zwei');
    Livewire::actingAs($this->bernd)->test(Home::class)->assertSee('Wann passt es dir? Grillabend');
    Livewire::actingAs($this->hanna)->test(Home::class)->assertDontSee('Wann passt es dir? Grillabend');
});

test('events can be for a Funktion; a Funktion includes its sub-functions', function () {
    $machinists = MemberFunction::create(['name' => 'Maschinist', 'sort_order' => 1]);
    $hlf = MemberFunction::create(['name' => 'Maschinist HLF', 'parent_id' => $machinists->id, 'sort_order' => 1]);
    $this->otto->functions()->attach($hlf);

    $event = app(EventService::class)->create(['title' => 'Maschinistenfortbildung', 'type' => 'uebung', 'starts_at' => Carbon::parse('2027-05-20 19:00', 'Europe/Berlin'),
        'ends_at' => Carbon::parse('2027-05-20 21:00', 'Europe/Berlin')], [], $this->manager, functionIds: [$machinists->id])->first();

    expect(app(EventService::class)->audience($event)->pluck('id')->all())->toBe([$this->otto->id])
        ->and(Event::visibleTo($this->otto)->pluck('id')->all())->toBe([$event->id])
        ->and(Event::visibleTo($this->hanna)->count())->toBe(0);

    // Keeping the Funktion when a form without it saves
    app(EventService::class)->update($event, ['title' => 'Maschinistenfortbildung', 'type' => 'uebung', 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at], []);
    expect($event->functions()->pluck('member_functions.id')->all())->toBe([$machinists->id]);
});

test('a Terminvorlage fills the form for a regular Arbeitsdienst', function () {
    Livewire::actingAs($this->manager)->test(EventForm::class)
        ->set('title', 'Arbeitsdienst Gerätehaus')->set('type', 'arbeitsdienst')->set('startsAt', '2027-05-08T09:00')->set('endsAt', '2027-05-08T12:00')
        ->set('location', 'Gerätehaus')->set('rsvp', true)->set('rsvpUntil', '2027-05-06T12:00')->set('minParticipants', '8')
        ->set('groupIds', [(string) $this->youth->id])
        ->set('templateName', 'Arbeitsdienst Samstag')->call('saveTemplate')->assertSee('gespeichert');

    expect(Template::sole()->data)->toMatchArray(['type' => 'arbeitsdienst', 'start_time' => '09:00', 'minutes' => 180, 'rsvp_minutes' => 2 * 24 * 60 - 180, 'min_participants' => 8, 'group_ids' => [$this->youth->id]]);

    Livewire::actingAs($this->manager)->test(EventForm::class)
        ->set('startsAt', '2027-06-12T19:00')->set('templateId', (string) Template::sole()->id)->call('applyTemplate')
        ->assertSet('title', 'Arbeitsdienst Gerätehaus')->assertSet('startsAt', '2027-06-12T09:00')->assertSet('endsAt', '2027-06-12T12:00')
        ->assertSet('rsvpUntil', '2027-06-10T12:00')->assertSet('groupIds', [(string) $this->youth->id])
        ->call('save')->assertHasNoErrors();
    expect(Event::sole())->type->toBe('arbeitsdienst')->min_participants->toBe(8);
});

test('the participant list prints; the poll pages open', function () {
    $event = kalenderEvent('2027-05-20 19:00', '21:00', ['rsvp' => true, 'type' => 'arbeitsdienst']);
    app(EventService::class)->respond($event, $this->hanna, 'yes', 'bringe Leiter mit');

    $this->actingAs($this->manager)->get(route('kalender.participants.print', $event))->assertOk()->assertSee('Arbeitsdienst: Übungsdienst')->assertSee('bringe Leiter mit');
    $this->actingAs($this->hanna)->get(route('kalender.participants.print', $event))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('kalender.polls'))->assertOk()->assertSee('Terminabfragen');
    $this->actingAs($this->manager)->get(route('kalender.polls.create'))->assertOk();
    $this->actingAs($this->hanna)->get(route('kalender.polls.create'))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('kalender.index'))->assertOk()->assertSee('Terminabfragen');
});
