<?php

use App\Models\Group;
use App\Models\User;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

// The dev container runs `migrate --seed` on creation; a failing seeder
// aborts its setup, so seeding must work on a fresh database and again.
test('the demo seeders run on a fresh database and can run again', function () {
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(Vehicle::count())->toBe(3)
        ->and(Group::where('name', 'Aktiver Dienst')->count())->toBe(1)
        ->and(User::where('email', 'admin@example.com')->value('is_admin'))->toBeTrue();
});
