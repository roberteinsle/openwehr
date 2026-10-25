<?php

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Uebungsdienste\src\Livewire\Planner;
use Modules\Uebungsdienste\src\Livewire\Planners;
use Modules\Uebungsdienste\src\Livewire\ServiceForm;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Models\Swap;
use Modules\Uebungsdienste\src\Notifications\ServiceExchangedNotification;
use Modules\Uebungsdienste\src\Notifications\SwapAnsweredNotification;
use Modules\Uebungsdienste\src\Notifications\SwapRequestedNotification;
use Modules\Uebungsdienste\src\Services\ServicePlan;

beforeEach(function () {
    Module::whereIn('key', ['uebungsdienste', 'fahrzeugbuchung'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-03-10 12:00', 'Europe/Berlin'));
    Notification::fake();

    $this->aktive = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $this->jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->planer = User::factory()->create(['name' => 'Paul Planer']);
    $this->planer->moduleAccess()->create(['module' => 'uebungsdienste', 'role' => 'übungsdienstplaner']);
    $this->jugendwart = User::factory()->create(['name' => 'Jana Jugendwart']);
    $this->jugendwart->moduleAccess()->create(['module' => 'uebungsdienste', 'role' => 'übungsdienstplaner']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->hanna->moduleAccess()->create(['module' => 'uebungsdienste']);
    $this->hanna->groups()->attach($this->aktive);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->bernd->moduleAccess()->create(['module' => 'uebungsdienste']);
    $this->bernd->groups()->attach($this->aktive);
    $this->plan = app(ServicePlan::class);
});

test('Übungsdienstplaner plan, change and delete; admins limit them to Abteilungen', function () {
    $service = $this->plan->add($this->planer, '2027-03-16', '19:00', 'Wache', ['organizer' => 'Vorstand']);
    expect($service->owner_group_id)->toBeNull()->and($service->organizer)->toBe('Vorstand');
    $this->plan->reschedule($this->planer, $service, '2027-03-17', '19:30', 'Wache');
    expect(fn () => $this->plan->add($this->hanna, '2027-03-18', '19:00', 'Wache'))->toThrow(ValidationException::class);

    // The Jugendwart plans for the Jugendfeuerwehr only
    Livewire::actingAs($this->admin)->test(Planners::class)->assertSee('Jana Jugendwart')
        ->set('scopes.'.$this->jugendwart->id, [(string) $this->jf->id])->call('save', $this->jugendwart->id)->assertSee('Gespeichert');
    expect(ServicePlan::ownerOptions($this->jugendwart))->toBe([$this->jf->id => 'Jugendfeuerwehr'])
        ->and(fn () => $this->plan->add($this->jugendwart, '2027-03-19', '18:00', 'Wache'))->toThrow(ValidationException::class)
        ->and(fn () => $this->plan->delete($this->jugendwart, $service))->toThrow(ValidationException::class);
    Livewire::actingAs($this->jugendwart)->test(Planners::class)->assertForbidden();

    $jfService = $this->plan->add($this->jugendwart, '2027-03-19', '18:00', 'Wache', ['owner_group_id' => $this->jf->id]);
    expect($jfService->group_ids)->toBe([$this->jf->id]);   // "Für wen?" defaults to the Abteilung

    // Dienste of the Jugendfeuerwehr: in the plan only for its members and planners
    Livewire::actingAs($this->hanna)->test(Planner::class)->assertSee('Mi, 17.03.2027')->assertDontSee('Fr, 19.03.2027');
    Livewire::actingAs($this->jugendwart)->test(Planner::class)->assertSee('Fr, 19.03.2027');

    // Several Abteilungen: the form asks for which one
    $this->plan->setScope($this->admin, $this->jugendwart, [$this->jf->id, $this->aktive->id]);
    Livewire::actingAs($this->jugendwart)->test(ServiceForm::class)->assertSee('Du planst für')
        ->set('ownerGroupId', (string) $this->aktive->id)->assertSet('groupIds', [(string) $this->aktive->id]);

    $this->plan->delete($this->planer, $service);
    expect(Service::find($service->id))->toBeNull();
});

test('a responsible Kamerad asks another to swap; the other one agrees or declines', function () {
    $mine = $this->plan->add($this->admin, '2027-03-16', '19:00', 'Wache');
    $theirs = $this->plan->add($this->admin, '2027-03-30', '19:00', 'Wache');
    $this->plan->addResponsible($mine, $this->hanna);
    $this->plan->addResponsible($theirs, $this->bernd);

    Livewire::actingAs($this->hanna)->test(Planner::class)
        ->call('startSwap', $mine->id)->assertSee('Di, 30.03.2027 – Bernd Blaulicht')
        ->set('swapWith', $theirs->id.':'.$this->bernd->id)->call('requestSwap')->assertHasNoErrors()->assertSee('Tausch angefragt');
    $swap = Swap::sole();
    Notification::assertSentTo($this->bernd, SwapRequestedNotification::class);

    // Everyone sees the waiting swap; a second request for the same Dienste is refused
    Livewire::actingAs($this->planer)->test(Planner::class)->assertSee('wartet auf Bernd Blaulicht');
    expect(fn () => $this->plan->requestSwap($this->hanna, $mine, $theirs, $this->bernd))->toThrow(ValidationException::class)
        ->and(fn () => $this->plan->answerSwap($this->hanna, $swap, true))->toThrow(ValidationException::class);

    Livewire::actingAs($this->bernd)->test(Planner::class)->call('answerSwap', $swap->id, true)->assertSee('Getauscht');
    expect($mine->responsible()->pluck('name')->all())->toBe(['Bernd Blaulicht'])
        ->and($theirs->responsible()->pluck('name')->all())->toBe(['Hanna Hydrant'])
        ->and($swap->fresh()->status)->toBe('accepted');
    Notification::assertSentTo($this->hanna, SwapAnsweredNotification::class);

    // Declined and withdrawn
    $back = $this->plan->requestSwap($this->hanna, $theirs, $mine, $this->bernd);
    $this->plan->answerSwap($this->bernd, $back, false);
    expect($theirs->responsible()->pluck('name')->all())->toBe(['Hanna Hydrant']);
    $again = $this->plan->requestSwap($this->hanna, $theirs, $mine, $this->bernd);
    $this->plan->withdrawSwap($this->hanna, $again);
    expect($again->fresh()->status)->toBe('withdrawn');

    // Only who is responsible can ask, and the others can't edit directly
    expect(fn () => $this->plan->requestSwap($this->bernd, $theirs, $mine, $this->hanna))->toThrow(ValidationException::class);
    Livewire::actingAs($this->hanna)->test(Planner::class)->call('removeResponsible', $theirs->id, $this->hanna->id)->assertHasErrors('plan');
});

test('the vehicles of a Dienst are booked from 30 minutes before to 30 minutes after; changes move, deleting frees them', function () {
    $mtw = Vehicle::factory()->create(['name' => 'MTW', 'is_active' => true]);
    $hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);

    Livewire::actingAs($this->planer)->test(ServiceForm::class)->assertSee('Fahrzeuge mitbuchen')
        ->set('date', '2027-03-16')->set('time', '19:00')->set('endTime', '21:00')->set('vehicleIds', [(string) $mtw->id, (string) $hlf->id])
        ->call('save')->assertRedirect();
    $service = Service::sole();
    $booking = Booking::where('vehicle_id', $mtw->id)->sole();
    expect($booking->starts_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('16.03.2027 18:30')
        ->and($booking->ends_at->setTimezone('Europe/Berlin')->format('H:i'))->toBe('21:30')
        ->and($booking->user_id)->toBe($this->planer->id)
        ->and($service->vehicles()->whereNotNull('booking_id')->count())->toBe(2);

    // Already booked: the Dienst is created, the vehicle not booked, the planner is told
    $second = $this->plan->add($this->planer, '2027-03-16', '20:00', 'Wache', ['vehicle_ids' => [$mtw->id]]);
    expect($this->plan->warnings()[0])->toContain('MTW')->toContain('nicht gebucht')
        ->and($second->vehicles()->sole()->booking_id)->toBeNull();

    // Moved: the bookings move along
    $this->plan->reschedule($this->planer, $service, '2027-03-23', '19:00', 'Wache');
    expect(Booking::whereNull('cancelled_at')->where('vehicle_id', $hlf->id)->sole()->starts_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i'))->toBe('23.03.2027 18:30')
        ->and($booking->fresh()->cancelled_at)->not->toBeNull();

    $this->plan->delete($this->planer, $service);
    expect(Booking::whereNull('cancelled_at')->where('vehicle_id', $hlf->id)->count())->toBe(0);
});

test('the Vorstand swaps topic and responsible people of two Dienste in one go; both sides are told', function () {
    $vorstand = MemberFunction::create(['name' => 'Vorstand']);
    $kassenwart = MemberFunction::create(['name' => 'Kassenwart', 'parent_id' => $vorstand->id]);
    $vera = User::factory()->create(['name' => 'Vera Vorstand']);
    $vera->moduleAccess()->create(['module' => 'uebungsdienste']);
    $vera->functions()->attach($kassenwart);

    $first = $this->plan->add($this->admin, '2027-03-16', '19:00', 'Wache');
    $second = $this->plan->add($this->admin, '2027-03-30', '19:00', 'Gerätehaus');
    $this->plan->setTopic($first, 'Knoten und Stiche');
    $this->plan->setTopic($second, 'Wasserförderung');
    $this->plan->addResponsible($first, $this->hanna);
    $this->plan->addResponsible($second, $this->bernd);
    $pending = $this->plan->requestSwap($this->hanna, $first, $second, $this->bernd);

    Livewire::actingAs($vera)->test(Planner::class)->assertSee('Als Vorstand tauschst du')
        ->call('startSwap', $first->id)->assertSee('Di, 30.03.2027 – Wasserförderung – Bernd Blaulicht')
        ->set('swapWith', (string) $second->id)->call('exchange')->assertHasNoErrors()->assertSee('Getauscht');

    expect($first->refresh())->topic->toBe('Wasserförderung')->location->toBe('Wache')
        ->and($first->responsible()->pluck('name')->all())->toBe(['Bernd Blaulicht'])
        ->and($second->refresh())->topic->toBe('Knoten und Stiche')
        ->and($second->responsible()->pluck('name')->all())->toBe(['Hanna Hydrant'])
        ->and($pending->fresh()->status)->toBe('withdrawn');
    Notification::assertSentTo($this->hanna, ServiceExchangedNotification::class, fn ($n) => str_contains($n->toMail($this->hanna)->subject, '30.03.2027'));
    Notification::assertSentTo($this->bernd, ServiceExchangedNotification::class);

    // Without the Vorstand Funktion: only asking the other one
    expect(fn () => $this->plan->exchange($this->hanna, $first, $second))->toThrow(ValidationException::class);
});
