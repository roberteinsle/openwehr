<?php

use App\Livewire\Dashboard\Home;
use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Schichtplan\src\Livewire\PlanCreate;
use Modules\Schichtplan\src\Livewire\SignupBoard;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Notifications\ShiftDecidedNotification;
use Modules\Schichtplan\src\Notifications\ShiftReminderNotification;
use Modules\Schichtplan\src\Notifications\ShiftRequestedNotification;
use Modules\Schichtplan\src\Notifications\SignupOpenNotification;
use Modules\Schichtplan\src\Services\PlanMailer;
use Modules\Schichtplan\src\Services\PlanService;
use Modules\Schichtplan\src\Services\ShiftPlanner;
use Modules\Schichtplan\src\Services\SignupService;

beforeEach(function () {
    Module::where('key', 'schichtplan')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-06-01 10:00', 'Europe/Berlin'));

    $this->youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $this->planer = User::factory()->create(['name' => 'Paul Planer']);
    $this->planer->moduleAccess()->create(['module' => 'schichtplan', 'role' => 'schichtplaner']);
    foreach (['hanna' => 'Hanna Hydrant', 'bernd' => 'Bernd Blaulicht', 'lead' => 'Lena Leitung', 'otto' => 'Otto Ortsbrand'] as $key => $name) {
        $this->{$key} = User::factory()->create(['name' => $name]);
        $this->{$key}->moduleAccess()->create(['module' => 'schichtplan']);
    }
    foreach ([$this->hanna, $this->bernd, $this->lead] as $user) {
        $user->groups()->attach($this->youth);
    }

    $this->plans = app(PlanService::class);
    $this->s = app(SignupService::class);
    $start = Carbon::parse('2027-06-12 14:00', 'Europe/Berlin');
    $this->plan = $this->plans->create(['title' => 'Arbeitsdienst Gerätehaus', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(4), 'shift_minutes' => 60,
        'max_consecutive' => 2, 'signup' => 'self', 'audience_groups' => [$this->youth->id]], $this->planer);
    $this->grill = $this->plans->addStation($this->plan, 'Grill', 1);
    $this->kasse = $this->plans->addStation($this->plan, 'Kasse', 2);
    $this->plans->setStationLead($this->grill, $this->lead->id);
    $this->slots = $this->plan->slots()->get();
});

function publishPlan(): void
{
    app(PlanMailer::class)->publish(test()->plan->refresh());
}

test('publishing a self sign-up plan invites the target group, nobody else sees it', function () {
    publishPlan();

    Notification::assertSentTo([$this->hanna, $this->bernd, $this->lead], SignupOpenNotification::class);
    Notification::assertNotSentTo($this->otto, SignupOpenNotification::class);
    $this->actingAs($this->hanna)->get(route('schichtplan.signup', $this->plan))->assertOk()->assertSee('Eintragen');
    $this->actingAs($this->otto)->get(route('schichtplan.signup', $this->plan))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('schichtplan.index'))->assertOk()->assertSee('Helfer gesucht')->assertSee('12 Plätze frei');
});

test('Kameraden take places themselves: capacity, one place per block, a break, a limit, and give them back', function () {
    publishPlan();
    [$one, $two, $three] = [$this->slots[0], $this->slots[1], $this->slots[2]];

    $a = $this->s->signUp($this->hanna, $this->plan, $one, $this->grill);
    expect($a)->status->toBe('confirmed')->locked->toBeTrue()->signed_up_by->toBe($this->hanna->id)
        ->and($this->plan->participants()->where('user_id', $this->hanna->id)->exists())->toBeTrue();

    expect(fn () => $this->s->signUp($this->bernd, $this->plan, $one, $this->grill))->toThrow(ValidationException::class, 'schon voll')
        ->and(fn () => $this->s->signUp($this->hanna, $this->plan, $one, $this->kasse))->toThrow(ValidationException::class, 'schon eingetragen');

    $this->s->signUp($this->hanna, $this->plan, $two, $this->kasse);
    expect(fn () => $this->s->signUp($this->hanna, $this->plan, $three, $this->kasse))->toThrow(ValidationException::class, 'am Stück');

    $this->plan->update(['max_shifts' => 2]);
    expect(fn () => $this->s->signUp($this->hanna, $this->plan->refresh(), $this->slots[3], $this->kasse))->toThrow(ValidationException::class, 'Höchstens 2');

    $this->s->withdraw($this->hanna, $a);
    expect(Assignment::where('user_id', $this->hanna->id)->count())->toBe(1);

    // The automatic distribution keeps self-taken places
    app(ShiftPlanner::class)->fill($this->plan->refresh(), 1);
    expect(Assignment::where('user_id', $this->hanna->id)->where('slot_id', $two->id)->exists())->toBeTrue();

    // After the deadline only the planer changes something
    $this->plan->update(['signup_until' => Carbon::parse('2027-06-10 18:00', 'Europe/Berlin')]);
    $this->travelTo(Carbon::parse('2027-06-11 08:00', 'Europe/Berlin'));
    $mine = Assignment::where('user_id', $this->hanna->id)->where('signed_up_by', $this->hanna->id)->sole();
    expect(fn () => $this->s->signUp($this->bernd, $this->plan->refresh(), $three, $this->kasse))->toThrow(ValidationException::class, 'nicht (mehr)')
        ->and(fn () => $this->s->withdraw($this->hanna, $mine))->toThrow(ValidationException::class, 'geschlossen');
    $this->s->withdraw($this->planer, $mine);
    expect(Assignment::find($mine->id))->toBeNull();
});

test('with approval a place is held until the Stationsverantwortung or the planer decides', function () {
    $this->plan->update(['needs_approval' => true]);
    publishPlan();

    $request = $this->s->signUp($this->hanna, $this->plan->refresh(), $this->slots[0], $this->grill);
    expect($request->status)->toBe('requested');
    Notification::assertSentTo([$this->planer, $this->lead], ShiftRequestedNotification::class);
    // A request holds the place
    expect(fn () => $this->s->signUp($this->bernd, $this->plan, $this->slots[0], $this->grill))->toThrow(ValidationException::class, 'schon voll');

    expect(fn () => $this->s->approve($this->bernd, $request))->toThrow(ValidationException::class);
    Livewire::actingAs($this->lead)->test(SignupBoard::class, ['plan' => $this->plan])
        ->assertSee('Anfragen bestätigen')->call('approve', $request->id)->assertSee('Bestätigt.');
    expect($request->fresh()->status)->toBe('confirmed');
    Notification::assertSentTo($this->hanna, ShiftDecidedNotification::class);

    $second = $this->s->signUp($this->bernd, $this->plan, $this->slots[1], $this->kasse);
    Livewire::actingAs($this->planer)->test(SignupBoard::class, ['plan' => $this->plan])
        ->set('rejectReason', 'Kasse ist schon doppelt besetzt')->call('reject', $second->id);
    expect(Assignment::find($second->id))->toBeNull()
        ->and($this->plan->participants()->where('user_id', $this->bernd->id)->exists())->toBeFalse();

    // The planer puts someone on directly, without a request
    expect($this->s->signUp($this->planer, $this->plan, $this->slots[2], $this->kasse, $this->bernd)->status)->toBe('confirmed');
});

test('Kameraden sign up and give back only themselves; the planer can pick a name', function () {
    publishPlan();

    Livewire::actingAs($this->hanna)->test(SignupBoard::class, ['plan' => $this->plan])
        ->assertDontSee('Wer trägt sich ein?')->assertDontSee('Kiosk')->set('personId', (string) $this->bernd->id)
        ->call('signUp', $this->slots[1]->id, $this->kasse->id)->assertSee('Hanna Hydrant ist eingetragen');
    expect(fn () => $this->s->signUp($this->hanna, $this->plan, $this->slots[0], $this->grill, $this->bernd))->toThrow(ValidationException::class, 'nur sich selbst');

    Livewire::actingAs($this->planer)->test(SignupBoard::class, ['plan' => $this->plan])
        ->assertSee('Wer trägt sich ein?')->assertSee('Bernd Blaulicht')->assertDontSee('Otto Ortsbrand')
        ->set('personId', (string) $this->bernd->id)->call('signUp', $this->slots[0]->id, $this->grill->id)->assertSee('Bernd Blaulicht ist eingetragen');
    $bernds = Assignment::where('user_id', $this->bernd->id)->sole();
    expect($bernds->signed_up_by)->toBe($this->planer->id);

    Livewire::actingAs($this->hanna)->test(SignupBoard::class, ['plan' => $this->plan])
        ->call('withdraw', $bernds->id)->assertHasErrors('signup');
    expect(fn () => $this->s->withdraw($this->hanna, $bernds))->toThrow(ValidationException::class, 'jemand anderem');
});

test('the target group can also be a Funktion, including its sub-functions', function () {
    $machinists = MemberFunction::create(['name' => 'Maschinist', 'sort_order' => 1]);
    $truck = MemberFunction::create(['name' => 'Maschinist HLF', 'parent_id' => $machinists->id, 'sort_order' => 1]);
    $this->otto->functions()->attach($truck);
    $this->plan->update(['audience_functions' => [$machinists->id]]);

    expect($this->plan->refresh()->inAudience($this->otto))->toBeTrue()
        ->and($this->plan->inAudience($this->hanna))->toBeTrue()
        ->and($this->plan->audienceLabel())->toBe('Jugendfeuerwehr, Maschinist');
});

test('the day before everyone gets one reminder of their confirmed shifts', function () {
    $this->plan->update(['needs_approval' => true]);
    publishPlan();
    $this->s->signUp($this->planer, $this->plan->refresh(), $this->slots[0], $this->grill, $this->hanna);
    $this->s->signUp($this->planer, $this->plan, $this->slots[1], $this->kasse, $this->hanna);
    $this->s->signUp($this->bernd, $this->plan, $this->slots[0], $this->kasse);   // still a request

    $this->travelTo(Carbon::parse('2027-06-11 15:00', 'Europe/Berlin'));
    $this->artisan('schichtplan:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->hanna, ShiftReminderNotification::class, 1);
    Notification::assertNotSentTo($this->bernd, ShiftReminderNotification::class);
    Notification::fake();
    $this->artisan('schichtplan:remind')->assertSuccessful();
    Notification::assertNothingSent();
});

test('the planer sets up a self sign-up plan; the start page shows open plans and requests', function () {
    Livewire::actingAs($this->planer)->test(PlanCreate::class)
        ->set('title', 'Dorffest Getränkestand')->set('date', '2027-07-03')->set('startTime', '12:00')->set('endTime', '20:00')
        ->set('signup', 'self')->set('signupUntil', '2027-07-01T18:00')->set('needsApproval', true)->set('maxShifts', '3')
        ->set('audienceGroups', [(string) $this->youth->id])
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $plan = Plan::where('title', 'Dorffest Getränkestand')->sole();
    expect($plan)->signup->toBe('self')->needs_approval->toBeTrue()->max_shifts->toBe(3)->audience_groups->toBe([$this->youth->id])
        ->and($plan->signup_until->setTimezone('Europe/Berlin')->format('d.m. H:i'))->toBe('01.07. 18:00');

    publishPlan();
    $this->plan->update(['needs_approval' => true]);
    $this->s->signUp($this->bernd, $this->plan->refresh(), $this->slots[0], $this->grill);
    Livewire::actingAs($this->hanna)->test(Home::class)->assertSee('Helfer gesucht: Arbeitsdienst Gerätehaus');
    Livewire::actingAs($this->lead)->test(Home::class)->assertSee('1 Schicht-Anfrage zu bestätigen');
    $this->actingAs($this->planer)->get(route('schichtplan.edit', $this->plan))->assertOk()->assertSee('Eintragung (1 Anfrage)');
});
