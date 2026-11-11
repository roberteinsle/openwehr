<?php

use App\Filament\Pages\AgeTransitionsPage;
use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GuardianResource\Pages\ListGuardians;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Module;
use App\Models\User;
use App\Notifications\GuestGroupNotification;
use App\Services\AgeTransitions;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;

require_once __DIR__.'/../Kalender/Helpers.php';

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Module::whereIn('key', ['schluessel', 'kalender'])->update(['is_active' => true, 'auto_assign' => true]);
    Cache::flush();
    $this->travelTo(Carbon::parse('2027-05-01 12:00', 'Europe/Berlin'));
    $this->admin = User::factory()->admin()->create(['phone' => null]);
    $this->aktive = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $this->jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $this->kid = User::factory()->create(['name' => 'Paul Pumpe', 'phone' => null, 'birth_date' => '2011-03-01']);   // 16
    $this->kid->groups()->attach($this->jf);
});

test('new Abteilungen get their colours by name and the Jugendfeuerwehr is a Jugendabteilung', function () {
    expect($this->aktive)->color->toBe('#ED2F12')->color_secondary->toBe('#1B2645')->is_youth->toBeFalse()
        ->and($this->jf)->color->toBe('#F28C28')->color_secondary->toBe('#1D4ED8')->is_youth->toBeTrue()
        ->and(Group::create(['name' => 'Musikzug'])->color)->toBeNull();

    $this->actingAs($this->admin);
    Livewire::test(EditGroup::class, ['record' => $this->jf->getRouteKey()])
        ->fillForm(['color' => '#FFAA00', 'excluded_modules' => ['schluessel'], 'guest_group_id' => $this->aktive->id, 'guest_from_age' => 16,
            'transfer_group_id' => $this->aktive->id, 'transfer_age' => 18])
        ->call('save')->assertHasNoFormErrors();
    expect($this->jf->fresh())->color->toBe('#FFAA00')->excluded_modules->toBe(['schluessel'])->guest_from_age->toBe(16);

    // The list of Kameraden shows the Abteilung in its colour
    Livewire::test(ListUsers::class)->assertSee('Jugendfeuerwehr')->assertSee('border-left: 4px solid #FFAA00', false);
});

test('a module switched off at a Kamerad stays off although it is given automatically', function () {
    expect($this->kid->canUseModule('schluessel'))->toBeTrue();

    $this->actingAs($this->admin);
    Livewire::test(EditUser::class, ['record' => $this->kid->getRouteKey()])
        ->assertFormSet(['module_access_schluessel' => true])
        ->fillForm(['module_access_schluessel' => false])->call('save')->assertHasNoFormErrors();

    expect($this->kid->fresh()->canUseModule('schluessel'))->toBeFalse()
        ->and($this->kid->fresh()->canUseModule('kalender'))->toBeTrue();
    $this->actingAs($this->kid)->get(route('home'))->assertOk()->assertDontSee('Schlüsselverwaltung');
    $this->actingAs($this->admin);
    Livewire::test(EditUser::class, ['record' => $this->kid->getRouteKey()])->assertFormSet(['module_access_schluessel' => false])
        ->fillForm(['module_access_schluessel' => true])->call('save');
    expect($this->kid->fresh()->canUseModule('schluessel'))->toBeTrue();

    // The bulk "Modul entziehen" also overrides the automatic access
    UserResource::revokeModule([$this->kid->id], 'schluessel');
    expect($this->kid->fresh()->canUseModule('schluessel'))->toBeFalse();
});

test('a Jugendabteilung keeps modules from members who are only there', function () {
    $this->jf->update(['excluded_modules' => ['schluessel']]);
    expect($this->kid->fresh()->canUseModule('schluessel'))->toBeFalse()->and($this->kid->fresh()->canUseModule('kalender'))->toBeTrue();

    // A Betreuer in both Abteilungen keeps it, an explicit grant counts for the child
    $betreuer = User::factory()->create();
    $betreuer->groups()->attach([$this->jf->id, $this->aktive->id]);
    expect($betreuer->canUseModule('schluessel'))->toBeTrue();
    $this->kid->moduleAccess()->create(['module' => 'schluessel']);
    expect($this->kid->fresh()->canUseModule('schluessel'))->toBeTrue();
});

test('the birth date is in the admin form again, optional', function () {
    $this->actingAs($this->admin);
    Livewire::test(EditUser::class, ['record' => $this->kid->getRouteKey()])
        ->assertFormSet(['birth_date' => '01.03.2011'])
        ->fillForm(['birth_date' => ''])->call('save')->assertHasNoFormErrors();
    expect($this->kid->fresh()->birth_date)->toBeNull();
    Livewire::test(EditUser::class, ['record' => $this->kid->getRouteKey()])->fillForm(['birth_date' => '02.04.2012'])->call('save')->assertHasNoFormErrors();
    expect($this->kid->fresh()->birth_date->toDateString())->toBe('2012-04-02');
});

test('Admin → Eltern lists the Erziehungsberechtigte with their child, searchable', function () {
    Guardian::create(['user_id' => $this->kid->id, 'name' => 'Petra Pumpe', 'email' => 'petra@example.com']);
    $adult = User::factory()->create(['name' => 'Otto Aktiv']);
    $adult->groups()->attach($this->aktive);
    Guardian::create(['user_id' => $adult->id, 'name' => 'Olga Aktiv', 'email' => 'olga@example.com']);

    $this->actingAs($this->admin);
    Livewire::test(ListGuardians::class)->assertSee('Petra Pumpe')->assertSee('Paul Pumpe')->assertDontSee('Olga Aktiv')
        ->assertSee('noch nie')
        ->removeTableFilter('youth')->assertSee('Olga Aktiv')
        ->searchTable('Pumpe')->assertSee('Petra Pumpe')->assertDontSee('Olga Aktiv');
    $this->actingAs($this->kid)->get('/admin/eltern')->assertForbidden();
});

test('from 16 a Jugendlicher joins the Übungen of the Einsatzabteilung as a guest, at 18 moves over', function () {
    Notification::fake();
    activateKalender();
    $this->jf->update(['guest_group_id' => $this->aktive->id, 'guest_from_age' => 16, 'transfer_group_id' => $this->aktive->id, 'transfer_age' => 18]);
    $younger = User::factory()->create(['name' => 'Pia Pumpe', 'birth_date' => '2013-01-01']);
    $younger->groups()->attach($this->jf);
    $unknown = User::factory()->create(['name' => 'Ole Ohnedatum']);
    $unknown->groups()->attach($this->jf);

    $transitions = app(AgeTransitions::class);
    expect($transitions->guestCandidates()->pluck('user.name')->all())->toBe(['Paul Pumpe'])
        ->and($transitions->transferCandidates())->toBeEmpty()
        ->and($transitions->missingBirthDates()->pluck('name')->all())->toBe(['Ole Ohnedatum']);

    $this->actingAs($this->admin);
    Livewire::test(AgeTransitionsPage::class)->assertSee('Paul Pumpe')->assertSee('Ole Ohnedatum')
        ->call('addGuest', $this->kid->id, $this->aktive->id);
    Notification::assertSentTo($this->kid, GuestGroupNotification::class);
    expect($transitions->guestCandidates())->toBeEmpty();

    // Übungen of the Einsatzabteilung reach the guest, other Termine of it don't
    $uebung = kalenderEvent('2027-05-10 19:00', '21:00', ['title' => 'Übung Aktive', 'type' => 'uebung'], [$this->aktive->id]);
    $fest = kalenderEvent('2027-05-12 19:00', '21:00', ['title' => 'Kameradschaftsabend', 'type' => 'veranstaltung'], [$this->aktive->id]);
    expect(Event::visibleTo($this->kid->fresh())->pluck('id')->all())->toContain($uebung->id)->not->toContain($fest->id)
        ->and(app(EventService::class)->audience($uebung)->pluck('id')->all())->toContain($this->kid->id)
        ->and(app(EventService::class)->audience($fest)->pluck('id')->all())->not->toContain($this->kid->id);
    // … but they stay in the Jugendfeuerwehr: no membership
    expect($this->kid->fresh()->groups->pluck('id')->all())->toBe([$this->jf->id]);

    // Two years later: Übertritt
    $this->travelTo(Carbon::parse('2029-03-02 12:00', 'Europe/Berlin'));
    expect($transitions->transferCandidates()->pluck('user.name')->all())->toBe(['Paul Pumpe']);
    Livewire::test(AgeTransitionsPage::class)->call('transfer', $this->kid->id, $this->jf->id, $this->aktive->id);
    $kid = $this->kid->fresh();
    expect($kid->groups->pluck('id')->all())->toBe([$this->aktive->id])->and($kid->guestGroups)->toBeEmpty();
});
