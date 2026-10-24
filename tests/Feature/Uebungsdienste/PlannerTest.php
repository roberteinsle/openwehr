<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Uebungsdienste\src\Livewire\Planner;
use Modules\Uebungsdienste\src\Livewire\ServiceForm;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\ServicePlan;

beforeEach(function () {
    Module::where('key', 'uebungsdienste')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-03-10 12:00', 'Europe/Berlin'));

    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->board = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->board->moduleAccess()->create(['module' => 'uebungsdienste']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);

    $this->plan = app(ServicePlan::class);
    $this->past = $this->plan->add($this->admin, '2027-03-02', '19:00', 'Wache');
    $this->next = $this->plan->add($this->admin, '2027-03-16', '19:00', 'Wache');
});

test('only the Vorstand (with access) and admins see the module', function () {
    $this->actingAs($this->board)->get('/uebungsdienste')->assertOk()->assertSee('Übungsdienstplaner')->assertSee('Drucken / PDF');
    $this->actingAs($this->admin)->get('/uebungsdienste')->assertOk();
    $this->actingAs($this->member)->get('/uebungsdienste')->assertForbidden();
});

test('the table shows date with weekday, start, place, and marks the next Dienst', function () {
    Livewire::actingAs($this->board)->test(Planner::class)
        ->set('showPast', true)
        ->assertSee('Di, 02.03.2027')
        ->assertSee('Di, 16.03.2027')
        ->assertSee('19:00 Uhr')
        ->assertSee('Wache')
        ->assertSee('Nächster Übungsdienst');
});

test('Übungsdienstplaner set the topic and several responsible people; others only see them', function () {
    Livewire::actingAs($this->board)->test(Planner::class)
        ->call('saveTopic', $this->next->id, 'Nur angucken')->assertHasErrors('plan');
    expect($this->next->refresh()->topic)->toBeNull();

    $this->board->moduleAccess()->update(['role' => 'übungsdienstplaner']);
    Livewire::actingAs($this->board)->test(Planner::class)
        ->call('saveTopic', $this->next->id, 'Technische Hilfe: Einsatz an der Bahn')
        ->call('addResponsible', $this->next->id, $this->member->id)
        ->call('addResponsible', $this->next->id, $this->board->id)
        ->assertHasNoErrors()
        ->assertSee('Hanna Hydrant')
        ->call('removeResponsible', $this->next->id, $this->board->id);

    $this->next->refresh();
    expect($this->next->topic)->toBe('Technische Hilfe: Einsatz an der Bahn')
        ->and($this->next->responsible->pluck('name')->all())->toBe(['Hanna Hydrant']);
});

test('date, time and place are changed by admins only, on their own page', function () {
    $this->actingAs($this->board)->get('/uebungsdienste/anlegen')->assertForbidden();
    $this->actingAs($this->board)->get('/uebungsdienste/'.$this->next->id.'/bearbeiten')->assertForbidden();
    Livewire::actingAs($this->board)->test(Planner::class)->assertDontSee('Dienst anlegen')->assertDontSee('Ändern');

    expect(fn () => $this->plan->reschedule($this->board, $this->next, '2027-03-17', '18:30', 'Gerätehaus'))->toThrow(ValidationException::class)
        ->and(fn () => $this->plan->delete($this->board, $this->next))->toThrow(ValidationException::class);

    $this->actingAs($this->admin)->get('/uebungsdienste/'.$this->next->id.'/bearbeiten')->assertOk()->assertSee('Dienst am Di, 16.03.2027 ändern');
    Livewire::actingAs($this->admin)->test(ServiceForm::class, ['service' => $this->next])
        ->set('date', '2027-03-17')->set('time', '18:30')->set('location', 'Gerätehaus')
        ->call('save')
        ->assertRedirect(route('uebungsdienste.index', ['jahr' => 2027]));

    expect($this->next->refresh())->starts_at->toBe('18:30')->location->toBe('Gerätehaus');
    Livewire::actingAs($this->admin)->test(Planner::class)->assertSee('Dienst am Mi, 17.03.2027 geändert');
});

test('admins add single Dienste and series on "Dienst anlegen", and delete them', function () {
    $this->actingAs($this->admin)->get('/uebungsdienste')->assertSee('Übungsdienst anlegen');
    $this->actingAs($this->admin)->get('/uebungsdienste/anlegen')->assertOk()->assertSee('Übungsdienst anlegen');

    Livewire::actingAs($this->admin)->test(ServiceForm::class)
        ->set('series', true)
        ->set('date', '2027-04-06')->set('every', 2)->set('until', '2027-05-04')->set('time', '19:30')->set('location', 'Wache')
        ->call('save')
        ->assertRedirect();

    expect(Service::whereDate('date', '>=', '2027-04-01')->orderBy('date')->pluck('date')->map->toDateString()->all())->toBe(['2027-04-06', '2027-04-20', '2027-05-04']);

    Livewire::actingAs($this->admin)->test(ServiceForm::class)->set('date', '2027-06-01')->set('time', '25:00')->call('save')->assertHasErrors('plan');

    Livewire::actingAs($this->admin)->test(ServiceForm::class, ['service' => $this->past])->call('delete')->assertRedirect(route('uebungsdienste.index'));
    expect(Service::find($this->past->id))->toBeNull();
});

test('past Dienste are hidden until asked for; the next one is on top', function () {
    Livewire::actingAs($this->board)->test(Planner::class)
        ->assertSee('Nächster Übungsdienst')
        ->assertDontSee('Di, 02.03.2027')
        ->assertSee('Vergangene Dienste anzeigen (1)')
        ->set('showPast', true)
        ->assertSee('Di, 02.03.2027')
        ->assertSee('Vergangene Dienste ausblenden');
});

test('the plan prints for the whole year or the coming Dienste only', function () {
    $this->plan->setTopic($this->next, 'Atemschutz: Absuchen von Räumen');

    $this->actingAs($this->board)->get('/uebungsdienste/druck?jahr=2027')->assertOk()
        ->assertSee('Übungsdienstplan 2027')->assertSee('Drucken / als PDF speichern')
        ->assertSee('Di, 02.03.2027')->assertSee('Atemschutz: Absuchen von Räumen');
    $this->actingAs($this->board)->get('/uebungsdienste/druck?jahr=2027&kommende=1')->assertOk()->assertDontSee('Di, 02.03.2027');
    $this->actingAs($this->member)->get('/uebungsdienste/druck')->assertForbidden();
});

test('years can be switched', function () {
    $this->plan->add($this->admin, '2028-01-11', '19:00', 'Wache');

    Livewire::actingAs($this->board)->test(Planner::class)
        ->assertSee('2028')
        ->set('year', 2028)
        ->assertSee('Di, 11.01.2028')
        ->assertViewHas('services', fn ($services) => $services->pluck('date')->map->toDateString()->all() === ['2028-01-11']);
});

test('the start page of the Vorstand shows the next Dienst', function () {
    $this->plan->setTopic($this->next, 'Atemschutz: Absuchen von Räumen');
    $this->plan->addResponsible($this->next, $this->member);

    Livewire::actingAs($this->board)->test(Home::class)
        ->assertSee('Nächster Übungsdienst')
        ->assertSee('Atemschutz: Absuchen von Räumen')
        ->assertSee('Hanna Hydrant');
    // Kameraden without access see the date, not the topic or who is responsible
    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Nächster Übungsdienst')->assertDontSee('Atemschutz: Absuchen von Räumen');
});

test('every Kamerad of the target group sees the next Übungsdienst on the start page, without topic', function () {
    $this->plan->setTopic($this->next, 'Technische Hilfe: Rettungssatz');

    Livewire::actingAs($this->member)->test(Home::class)
        ->assertSee('Nächster Übungsdienst')->assertSee('19:00')->assertDontSee('Rettungssatz')->assertDontSee(route('uebungsdienste.index'));
    Livewire::actingAs($this->board)->test(Home::class)
        ->assertSee('Nächster Übungsdienst')->assertSee('Rettungssatz');
});
