<?php

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Notifications\EventCancelledNotification;
use Modules\Kalender\src\Notifications\EventCreatedNotification;
use Modules\Kalender\src\Services\EventService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    activateKalender();
    $this->events = app(EventService::class);
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
});

test('a weekly repetition keeps 19:00 across the change to winter time', function () {
    $start = Carbon::parse('2027-10-19 19:00', 'Europe/Berlin');
    $events = $this->events->create(['title' => 'Übungsdienst', 'type' => 'uebung', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2)], [], null,
        ['freq' => 'weekly', 'until' => Carbon::parse('2027-11-09', 'Europe/Berlin')]);

    expect($events)->toHaveCount(4)
        ->and($events->map(fn (Event $e) => $e->starts_at->copy()->setTimezone('Europe/Berlin')->format('d.m. H:i'))->all())
        ->toBe(['19.10. 19:00', '26.10. 19:00', '02.11. 19:00', '09.11. 19:00'])
        ->and($events->pluck('series_id')->unique())->toHaveCount(1);
});

test('fortnightly and monthly repetitions, the 31st falls back to the last day', function () {
    $biweekly = $this->events->occurrences(Carbon::parse('2027-06-01 19:00', 'Europe/Berlin'), ['freq' => 'biweekly', 'until' => Carbon::parse('2027-07-01', 'Europe/Berlin')]);
    expect(array_map(fn ($d) => $d->format('d.m.'), $biweekly))->toBe(['01.06.', '15.06.', '29.06.']);

    $monthly = $this->events->occurrences(Carbon::parse('2027-01-31 18:00', 'Europe/Berlin'), ['freq' => 'monthly', 'until' => Carbon::parse('2027-04-30', 'Europe/Berlin')]);
    expect(array_map(fn ($d) => $d->format('d.m.'), $monthly))->toBe(['31.01.', '28.02.', '31.03.', '30.04.']);
});

test('an end before the start is refused', function () {
    expect(fn () => kalenderEvent('2027-06-15 19:00', '18:00'))->toThrow(ValidationException::class);
});

test('events for an Abteilung are only seen and announced there', function () {
    Notification::fake();
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $kid = kalenderUser();
    $kid->groups()->attach($youth);
    $active = kalenderUser();
    $manager = kalenderUser('terminverwalter');
    $noAccess = User::factory()->create();
    $noAccess->groups()->attach($youth);

    $start = Carbon::parse('2027-06-20 10:00', 'Europe/Berlin');
    $event = $this->events->create(['title' => 'JF-Zeltlager', 'type' => 'veranstaltung', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(5)], [$youth->id], $manager, null, notify: true)->first();
    $forAll = kalenderEvent('2027-06-21 19:00');

    expect(Event::visibleTo($kid)->pluck('id')->all())->toContain($event->id, $forAll->id)
        ->and(Event::visibleTo($active)->pluck('id')->all())->toBe([$forAll->id])
        ->and(Event::visibleTo($manager)->count())->toBe(2);

    Notification::assertSentTo($kid, EventCreatedNotification::class);
    Notification::assertNotSentTo([$active, $noAccess, $manager], EventCreatedNotification::class);
});

test('Rückmeldungen only from the audience, changeable until the start (after the Frist too)', function () {
    $member = kalenderUser();
    $event = kalenderEvent('2027-06-15 19:00', attributes: ['rsvp' => true, 'rsvp_deadline' => Carbon::parse('2027-06-10 23:59', 'Europe/Berlin')]);

    $this->events->respond($event, $member, 'yes', 'komme später');
    $this->events->respond($event, $member, 'maybe');
    expect($event->responses()->sole())->status->toBe('maybe')->comment->toBeNull();

    $outsider = User::factory()->create();
    expect(fn () => $this->events->respond($event, $outsider, 'yes'))->toThrow(ValidationException::class);
    expect(fn () => $this->events->respond($event, $member, 'vielleicht'))->toThrow(ValidationException::class);

    // After the Frist: still possible (marked as late on the event page), after the start not
    $this->travelTo(Carbon::parse('2027-06-11 08:00', 'Europe/Berlin'));
    expect($this->events->respond($event, $member, 'no')->status)->toBe('no')->and($event->pastDeadline())->toBeTrue();
    $this->travelTo(Carbon::parse('2027-06-15 19:30', 'Europe/Berlin'));
    expect(fn () => $this->events->respond($event, $member, 'yes'))->toThrow(ValidationException::class);

    $noRsvp = kalenderEvent('2027-06-20 19:00');
    expect(fn () => $this->events->respond($noRsvp, $member, 'yes'))->toThrow(ValidationException::class);
});

test('the summary lists answers and who is still open', function () {
    [$anna, $ben, $clara] = [kalenderUser(attributes: ['name' => 'Anna']), kalenderUser(attributes: ['name' => 'Ben']), kalenderUser(attributes: ['name' => 'Clara'])];
    $event = kalenderEvent('2027-06-15 19:00', attributes: ['rsvp' => true]);
    $this->events->respond($event, $anna, 'yes');
    $this->events->respond($event, $ben, 'no');

    $summary = $this->events->summary($event);

    expect($summary['yes']->pluck('user.name')->all())->toBe(['Anna'])
        ->and($summary['no']->pluck('user.name')->all())->toBe(['Ben'])
        ->and($summary['open']->pluck('name')->all())->toBe(['Clara']);
});

test('cancelling tells everyone who said yes or maybe', function () {
    Notification::fake();
    [$yes, $maybe, $no] = [kalenderUser(), kalenderUser(), kalenderUser()];
    $event = kalenderEvent('2027-06-15 19:00', attributes: ['rsvp' => true]);
    $this->events->respond($event, $yes, 'yes');
    $this->events->respond($event, $maybe, 'maybe');
    $this->events->respond($event, $no, 'no');

    $this->events->cancel($event, null);

    expect($event->refresh()->isCancelled())->toBeTrue()->and($event->acceptsResponses())->toBeFalse();
    Notification::assertSentTo([$yes, $maybe], EventCancelledNotification::class);
    Notification::assertNotSentTo($no, EventCancelledNotification::class);
});

test('changes can go to the following events of a series, times stay per event', function () {
    $start = Carbon::parse('2027-06-01 19:00', 'Europe/Berlin');
    $series = $this->events->create(['title' => 'Übung', 'type' => 'uebung', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2)], [], null,
        ['freq' => 'weekly', 'until' => Carbon::parse('2027-06-29', 'Europe/Berlin')]);
    $second = $series[1];

    $this->events->update($second, [
        'title' => 'Übung Atemschutz', 'type' => 'uebung', 'location' => 'Übungshaus',
        'starts_at' => Carbon::parse('2027-06-08 18:30', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-06-08 21:00', 'Europe/Berlin'),
    ], [], following: true);

    $titles = Event::orderBy('starts_at')->pluck('title')->all();
    expect($titles)->toBe(['Übung', 'Übung Atemschutz', 'Übung Atemschutz', 'Übung Atemschutz', 'Übung Atemschutz'])
        ->and($series[2]->refresh()->location)->toBe('Übungshaus')
        ->and($series[2]->starts_at->copy()->setTimezone('Europe/Berlin')->format('H:i'))->toBe('19:00')
        ->and($second->refresh()->starts_at->copy()->setTimezone('Europe/Berlin')->format('H:i'))->toBe('18:30');

    $this->events->delete($series[3]->refresh(), following: true);
    expect(Event::count())->toBe(3);
});
