<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;
use Modules\Schichtplan\src\Livewire\PlanCreate;
use Modules\Schichtplan\src\Livewire\PlanEditor;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Services\PlanMailer;
use Modules\Schichtplan\src\Services\PlanService;

beforeEach(function () {
    Module::whereIn('key', ['schichtplan', 'kalender'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-06-01 10:00', 'Europe/Berlin'));

    $this->youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $this->planer = User::factory()->create(['name' => 'Paul Planer']);
    $this->planer->moduleAccess()->create(['module' => 'schichtplan', 'role' => 'schichtplaner']);
    $this->planer->moduleAccess()->create(['module' => 'kalender']);
    foreach (['hanna' => 'Hanna Hydrant', 'otto' => 'Otto Ortsbrand'] as $key => $name) {
        $this->{$key} = User::factory()->create(['name' => $name]);
        $this->{$key}->moduleAccess()->create(['module' => 'schichtplan']);
        $this->{$key}->moduleAccess()->create(['module' => 'kalender']);
    }
    $this->hanna->groups()->attach($this->youth);
    $this->plans = app(PlanService::class);
});

function linkedPlan(array $attributes = []): Plan
{
    $start = Carbon::parse('2027-06-12 14:00', 'Europe/Berlin');
    $plan = test()->plans->create(['title' => 'Arbeitsdienst Gerätehaus', 'location' => 'Gerätehaus', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(4),
        'shift_minutes' => 60, 'max_consecutive' => 2, 'signup' => 'self', 'audience_groups' => [test()->youth->id], ...$attributes], test()->planer);
    test()->plans->addStation($plan, 'Hof fegen', 3);

    return $plan;
}

test('publishing puts the plan into the calendar for its target group; changes follow; deleting the plan removes it', function () {
    $plan = linkedPlan();
    expect(Event::count())->toBe(0);

    app(PlanMailer::class)->publish($plan->refresh());
    $event = Event::sole();
    expect($plan->refresh())->event_id->toBe($event->id)->event_owned->toBeTrue()
        ->and($event)->title->toBe('Arbeitsdienst Gerätehaus')->location->toBe('Gerätehaus')
        ->and($event->starts_at->equalTo($plan->starts_at))->toBeTrue()
        ->and($event->groups()->pluck('groups.id')->all())->toBe([$this->youth->id])
        ->and(Event::visibleTo($this->otto)->count())->toBe(0);

    $this->actingAs($this->hanna)->get(route('kalender.show', $event))->assertOk()->assertSee('Helfer eintragen')->assertSee(route('schichtplan.signup', $plan), false);
    $this->actingAs($this->hanna)->get(route('schichtplan.show', $plan))->assertOk()->assertSee('Im Kalender ansehen');

    $this->plans->update($plan, ['title' => 'Arbeitsdienst Hof', 'starts_at' => $plan->starts_at->copy()->addHour(), 'ends_at' => $plan->ends_at->copy()->addHour(), 'shift_minutes' => 60]);
    expect($event->refresh())->title->toBe('Arbeitsdienst Hof')->and($event->starts_at->equalTo($plan->refresh()->starts_at))->toBeTrue();

    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $plan])->call('delete');
    expect(Event::count())->toBe(0);
});

test('a planer plan is in the calendar of the people on it; an event deleted in the Kalender stays deleted', function () {
    $plan = linkedPlan(['signup' => 'planner', 'audience_groups' => []]);
    $this->plans->addParticipants($plan, [$this->otto->id]);
    app(PlanMailer::class)->publish($plan->refresh());

    $event = Event::sole();
    expect($event->invitees()->pluck('users.id')->sort()->values()->all())->toBe(collect([$this->planer->id, $this->otto->id])->sort()->values()->all())
        ->and(Event::visibleTo($this->hanna)->count())->toBe(0);
    $this->plans->addParticipants($plan, [$this->hanna->id]);
    expect(Event::visibleTo($this->hanna)->count())->toBe(1);

    app(EventService::class)->delete($event);
    $this->plans->update($plan->refresh(), ['title' => 'Neu', 'starts_at' => $plan->starts_at, 'ends_at' => $plan->ends_at, 'shift_minutes' => 60]);
    expect(Event::count())->toBe(0);
});

test('"Schichtplan anlegen" on an event takes its data and links the plan; the event stays the Terminverwalter\'s', function () {
    $manager = User::factory()->create(['name' => 'Tina Terminverwalter']);
    $manager->moduleAccess()->create(['module' => 'kalender', 'role' => 'terminverwalter']);
    $event = app(EventService::class)->create(['title' => 'Tag der offenen Tür', 'type' => 'veranstaltung', 'location' => 'Festplatz',
        'starts_at' => Carbon::parse('2027-07-03 11:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-07-03 18:00', 'Europe/Berlin')], [$this->youth->id], $manager)->first();

    $this->actingAs($this->planer)->get(route('kalender.show', $event))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('kalender.show', $event))->assertOk()->assertDontSee('Schichtplan anlegen');
    $this->planer->groups()->attach($this->youth);
    $this->actingAs($this->planer)->get(route('kalender.show', $event))->assertOk()->assertSee('Schichtplan anlegen');

    Livewire::actingAs($this->planer)->withQueryParams(['termin' => $event->id])->test(PlanCreate::class)
        ->assertSet('title', 'Tag der offenen Tür')->assertSet('location', 'Festplatz')->assertSet('date', '2027-07-03')
        ->assertSet('startTime', '11:00')->assertSet('endTime', '18:00')->assertSet('audienceGroups', [(string) $this->youth->id])
        ->assertSee('Für den Kalendertermin')
        ->set('signup', 'self')->call('save')->assertHasNoErrors();

    $plan = Plan::sole();
    expect($plan)->event_id->toBe($event->id)->event_owned->toBeFalse();
    app(PlanMailer::class)->publish($plan);
    expect(Event::count())->toBe(1)->and($event->refresh()->title)->toBe('Tag der offenen Tür');
    $this->actingAs($this->hanna)->get(route('kalender.show', $event))->assertSee('Helfer eintragen');

    Livewire::actingAs($this->planer)->test(PlanEditor::class, ['plan' => $plan])->call('delete');
    expect(Event::count())->toBe(1);
});

test('without the Kalender nothing is linked', function () {
    Module::where('key', 'kalender')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $plan = linkedPlan();
    app(PlanMailer::class)->publish($plan->refresh());

    expect(Event::count())->toBe(0);
    Livewire::actingAs($this->planer)->withQueryParams(['termin' => 1])->test(PlanCreate::class)->assertSet('eventId', null);
    $this->actingAs($this->hanna)->get(route('schichtplan.show', $plan))->assertOk()->assertDontSee('Im Kalender');
});
