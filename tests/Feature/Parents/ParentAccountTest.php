<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Contact;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Services\ParentOnlyAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Personalverwaltung\src\Services\PersonnelService;

beforeEach(function () {
    RateLimiter::clear('magic-link:ip:127.0.0.1');
    $this->jf = Group::create(['name' => 'Jugendfeuerwehr', 'is_youth' => true, 'color' => '#2563EB']);
    $this->aktive = Group::create(['name' => 'Aktive']);
    $this->child = User::factory()->create(['name' => 'Paul Pumpe', 'email' => 'paul@example.com']);
    $this->child->groups()->attach($this->jf);
    Guardian::create(['user_id' => $this->child->id, 'name' => 'Petra Pumpe', 'email' => 'petra@example.com', 'phone' => '0170 1234567']);
});

// ─── Nur Elternteil ───────────────────────────────────────────────────────

test('a Kamerad account made only for a parent becomes the Eltern-Login with the same address', function () {
    $mum = User::factory()->create(['name' => 'Petra Pumpe', 'email' => 'petra@example.com']);
    $admin = User::factory()->admin()->create();
    expect(ParentOnlyAccount::candidate($mum))->toBeTrue()
        ->and(ParentOnlyAccount::candidate($admin))->toBeFalse()
        ->and(ParentOnlyAccount::candidate($this->child))->toBeFalse();

    $this->actingAs($admin);
    Livewire::test(EditUser::class, ['record' => $mum->getRouteKey()])
        ->assertActionVisible('parent_only')
        ->callAction('parent_only');

    expect(User::find($mum->id))->toBeNull();
    auth()->logout();

    // The same address now signs in to the Eltern-Bereich
    $this->post(route('auth.magic-link.send'), ['email' => 'petra@example.com']);
    expect(LoginToken::sole()->guardian_id)->not->toBeNull()->and(LoginToken::sole()->user_id)->toBeNull();
});

test('an account with data on it stays as Ausgetreten without address', function () {
    $mum = User::factory()->create(['email' => 'petra@example.com']);
    // A booking keeps a restrictive foreign key to its user
    Booking::factory()->create(['user_id' => $mum->id]);

    expect(app(ParentOnlyAccount::class)->convert($mum, User::factory()->admin()->create()))->toBeFalse()
        ->and($mum->refresh())->is_active->toBeFalse()->email->toBeNull();

    $this->post(route('auth.magic-link.send'), ['email' => 'petra@example.com']);
    expect(LoginToken::sole()->guardian_id)->not->toBeNull();
});

test('without a child behind the address the action is not offered', function () {
    $other = User::factory()->create(['email' => 'niemand@example.com']);

    expect(ParentOnlyAccount::candidate($other))->toBeFalse();
    expect(fn () => app(ParentOnlyAccount::class)->convert($other, $other))->toThrow(RuntimeException::class);
});

// ─── Kameraden who are parents ────────────────────────────────────────────

test('a Kamerad who is a parent sees the Eltern-Bereich card with the children on the start page', function () {
    $mum = User::factory()->create(['email' => 'petra@example.com']);
    $mum->groups()->attach($this->aktive);

    $this->actingAs($mum)->get('/')->assertOk()
        ->assertSee('data-parent-card', false)->assertSee('Paul Pumpe')->assertSee('Jugendfeuerwehr');

    $this->actingAs(User::factory()->create())->get('/')->assertDontSee('data-parent-card', false);
});

// ─── Ansprechpartner per Abteilung ────────────────────────────────────────

test('Ansprechpartner limited to Abteilungen are seen by their members, the parents of their children and admins', function () {
    Contact::create(['role' => 'Wehrführer', 'name' => 'Bernd Blaulicht']);
    Contact::create(['role' => 'Jugendwartin', 'name' => 'Hanna Hydrant', 'visible_group_ids' => [$this->jf->id]]);
    Contact::create(['role' => 'Gerätewart', 'name' => 'Gerd Gerät', 'visible_group_ids' => [$this->aktive->id]]);
    Module::where('key', 'kalender')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $kamerad = User::factory()->create();
    $kamerad->groups()->attach($this->aktive);
    $this->actingAs($kamerad)->get('/ansprechpartner')->assertSee('Bernd Blaulicht')->assertSee('Gerd Gerät')->assertDontSee('Hanna Hydrant');
    $this->actingAs($this->child)->get('/ansprechpartner')->assertSee('Hanna Hydrant')->assertDontSee('Gerd Gerät');
    $this->actingAs(User::factory()->admin()->create())->get('/ansprechpartner')->assertSee('Hanna Hydrant')->assertSee('Gerd Gerät');

    // The parent in the Eltern-Bereich sees what the child sees
    auth()->logout();
    $this->actingAs(Guardian::first(), 'guardian')->get(route('parents.child', $this->child))->assertOk()
        ->assertSee('data-parent-contacts', false)->assertSee('Hanna Hydrant')->assertSee('Bernd Blaulicht')->assertDontSee('Gerd Gerät');
});

// ─── JF login ─────────────────────────────────────────────────────────────

test('a JF login gets the Jugendfeuerwehr Dienstausweis and sees its parents on the Profil', function () {
    Module::where('key', 'personalverwaltung')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Setting::set('personalverwaltung.fields', json_encode(['digital_id' => true]));

    expect(PersonnelService::digitalIdFor($this->child))->toMatchArray(['youth' => true, 'youth_color' => '#2563EB']);
    $this->actingAs($this->child)->get(route('personalverwaltung.digital-id'))->assertOk()
        ->assertSee('JUGENDFEUERWEHR')->assertSee('Mitglied der Jugendfeuerwehr');

    $adult = User::factory()->create();
    $adult->groups()->attach($this->aktive);
    expect(PersonnelService::digitalIdFor($adult)['youth'])->toBeFalse();

    $this->actingAs($this->child)->get(route('profile'))->assertOk()
        ->assertSee('Erziehungsberechtigte')->assertSee('Petra Pumpe')->assertSee('petra@example.com')->assertSee('0170 1234567');
    $this->actingAs($adult)->get(route('profile'))->assertDontSee('data-profile-guardians', false);
});
