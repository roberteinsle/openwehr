<?php

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use App\Services\OrganizationSettings;
use App\Support\FireRanks;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

// ─── Dienstgrad ──────────────────────────────────────────────────────────────

test('ranks come as fixed lists for male and female', function () {
    $male = FireRanks::for('male');
    $female = FireRanks::for('female');

    expect($male)->toHaveCount(17)->and($female)->toHaveCount(17)
        ->and($male[0])->toBe('Feuerwehrmann-Anwärter')->and($male[16])->toBe('Landesbrandmeister')
        ->and($female[0])->toBe('Feuerwehrfrau-Anwärterin')->and($female[16])->toBe('Landesbrandmeisterin')
        ->and($male)->toContain('Hauptfeuerwehrmann **', 'Hauptbrandmeister ***', 'Erster Hauptbrandmeister *')
        ->and($female)->toContain('Hauptfeuerwehrfrau ***', 'Erste Hauptbrandmeisterin *');
    expect(FireRanks::for('diverse'))->toBeNull()->and(FireRanks::for(null))->toBeNull();
});

test('a rank moves to the same step when the gender changes', function () {
    expect(FireRanks::translate('Oberlöschmeister', 'male', 'female'))->toBe('Oberlöschmeisterin')
        ->and(FireRanks::translate('Hauptfeuerwehrfrau **', 'female', 'male'))->toBe('Hauptfeuerwehrmann **')
        ->and(FireRanks::translate('Jugendwart', 'male', 'female'))->toBe('Jugendwart')
        ->and(FireRanks::translate('Brandmeister', 'male', 'diverse'))->toBe('Brandmeister');
});

test('a male member picks the rank from the list', function () {
    $user = User::factory()->create(['phone' => null]);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['gender' => 'male', 'rank' => 'Oberbrandmeister'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->rank)->toBe('Oberbrandmeister');
});

test('a rank of the other list is refused', function () {
    $user = User::factory()->create(['phone' => null]);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['gender' => 'female', 'rank' => 'Oberbrandmeister'])
        ->call('save')
        ->assertHasFormErrors(['rank']);
});

test('a diverse member has a free-text rank', function () {
    $user = User::factory()->create(['phone' => null]);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['gender' => 'diverse', 'rank' => 'Jugendwart-Stellvertretung'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->rank)->toBe('Jugendwart-Stellvertretung');
});

test('an older free-text rank is kept while it is not changed', function () {
    $user = User::factory()->create(['phone' => null, 'gender' => 'male', 'rank' => 'Gruppenführer']);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['notes' => 'Neue Notiz'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->rank)->toBe('Gruppenführer');
});

// ─── Liste ───────────────────────────────────────────────────────────────────

test('the list is called Kameraden', function () {
    expect(UserResource::getNavigationLabel())->toBe('Kameraden')
        ->and(UserResource::getPluralModelLabel())->toBe('Kameraden');
});

test('the e-mail column can be hidden and the Funktionen are listed instead of the modules', function () {
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $machinist = MemberFunction::create(['name' => 'Maschinist', 'sort_order' => 1]);
    $member->functions()->attach([$machinist->id, MemberFunction::create(['name' => 'HLF', 'parent_id' => $machinist->id, 'sort_order' => 1])->id]);

    Livewire::test(ListUsers::class)
        ->assertTableColumnExists('email', fn ($column) => $column->isToggleable())
        ->assertTableColumnDoesNotExist('modules')
        ->assertTableColumnStateSet('functions', ['Maschinist', 'Maschinist › HLF'], $member);
});

// ─── Module für viele ────────────────────────────────────────────────────────

test('the header action grants a module to everyone the filters match, beyond the first page', function () {
    $youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 1]);
    $inGroup = User::factory()->count(30)->create()->each(fn (User $u) => $u->groups()->attach($youth));
    $other = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->filterTable('groups', [$youth->id])
        ->callAction('grant_module_all', data: ['modules' => ['maengelmanagement'], 'role_maengelmanagement' => 'verantwortlicher']);

    expect(DB::table('module_user')->where('module', 'maengelmanagement')->whereIn('user_id', $inGroup->pluck('id'))->count())->toBe(30);
    expect($other->moduleAccess()->exists())->toBeFalse();
    expect(DB::table('module_user')->where('module', 'maengelmanagement')->where('role', 'verantwortlicher')->count())->toBe(30);
});

test('several modules are ticked at once; a role someone already has stays without a new one', function () {
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $hanna->moduleAccess()->create(['module' => 'fahrzeugbuchung', 'role' => 'entscheider']);
    $bernd = User::factory()->create(['name' => 'Bernd Blaulicht']);

    Livewire::test(ListUsers::class)
        ->callAction('grant_module_all', data: ['modules' => []])
        ->assertHasActionErrors(['modules' => 'required']);

    Livewire::test(ListUsers::class)
        ->searchTable('Hydrant')
        ->callAction('grant_module_all', data: ['modules' => ['fahrzeugbuchung', 'kalender', 'maengelmanagement'], 'role_maengelmanagement' => 'verantwortlicher'])
        ->assertHasNoActionErrors()
        ->assertNotified('Module „Fahrzeugbuchung“, „Kalender & Termine“, „Mängelmanagement“ für 1 Kameraden freigeschaltet');

    expect($hanna->moduleAccess()->pluck('role', 'module')->all())
        ->toEqualCanonicalizing(['fahrzeugbuchung' => 'entscheider', 'kalender' => null, 'maengelmanagement' => 'verantwortlicher'])
        ->and($bernd->moduleAccess()->exists())->toBeFalse();
});

test('the header action revokes a module from the filtered members only', function () {
    $keep = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $drop = User::factory()->create(['name' => 'Hanna Hydrant']);
    foreach ([$keep, $drop] as $user) {
        $user->moduleAccess()->create(['module' => 'fahrzeugbuchung']);
    }

    Livewire::test(ListUsers::class)
        ->searchTable('Hanna')
        ->callAction('revoke_module_all', data: ['module' => 'fahrzeugbuchung']);

    expect($drop->moduleAccess()->exists())->toBeFalse()->and($keep->moduleAccess()->exists())->toBeTrue();
});

test('mobile number and address are hidden, the birth date is shown; stored values stay', function () {
    $user = User::factory()->create(['phone' => '04101 12345', 'birth_date' => '1985-03-02', 'street' => 'Hauptstraße 1', 'postal_code' => '12345', 'city' => 'Musterstadt']);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->assertFormFieldIsHidden('phone')
        ->assertFormFieldIsVisible('birth_date')
        ->assertFormFieldIsHidden('street')
        ->assertFormFieldIsHidden('postal_code')
        ->assertFormFieldIsHidden('city')
        ->fillForm(['notes' => 'Neue Notiz'])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();
    expect($user->phone)->toBe('04101 12345')
        ->and($user->birth_date->format('Y-m-d'))->toBe('1985-03-02')
        ->and($user->street)->toBe('Hauptstraße 1')
        ->and($user->postal_code)->toBe('12345')
        ->and($user->city)->toBe('Musterstadt');
});

test('the app version sits small behind the admin title', function () {
    expect(file_get_contents(resource_path('views/filament/brand.blade.php')))->toContain('font-size: .7rem');

    $this->actingAs($this->admin)->get('/admin/kameraden')
        ->assertOk()
        ->assertSeeInOrder([app(OrganizationSettings::class)->shortName().' Admin', config('app.version')]);
});

test('the Kameraden list sorts by last login by default, most recent first, never-logged-in last', function () {
    $never = User::factory()->create(['name' => 'Nie Angemeldet', 'last_login_at' => null]);
    $old = User::factory()->create(['name' => 'Bernd Blaulicht', 'last_login_at' => now()->subMonth()]);
    $recent = User::factory()->create(['name' => 'Hanna Hydrant', 'last_login_at' => now()->subHour()]);
    $this->admin->update(['last_login_at' => now()->subDay()]);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$recent, $this->admin, $old, $never], inOrder: true)
        ->sortTable('last_login_at', 'asc')
        ->assertCanSeeTableRecords([$old, $this->admin, $recent, $never], inOrder: true)
        ->sortTable('name')
        ->assertCanSeeTableRecords([$old, $recent, $never], inOrder: true);
});
