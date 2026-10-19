<?php

use App\Enums\Role;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Access\PermissionSynchronizer;
use App\Support\Kiln\CurrentKiln;
use App\Support\Kiln\KilnDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests run against the real MySQL test database (gbricks_test), inside a transaction
| that is rolled back after every test.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        app(PermissionSynchronizer::class)->sync();
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

require_once __DIR__.'/Support/accounting.php';
require_once __DIR__.'/Support/parties.php';
require_once __DIR__.'/Support/vouchers.php';
require_once __DIR__.'/Support/materials.php';

/** Create an active kiln. */
function makeKiln(array $attributes = []): Kiln
{
    return Kiln::factory()->create($attributes);
}

/** Create an active kiln with the default units, grades, stages and expense categories. */
function makeSeededKiln(array $attributes = []): Kiln
{
    $kiln = makeKiln($attributes);
    app(KilnDefaults::class)->seed($kiln);

    return $kiln;
}

/** Create a user with a role in the given kiln. */
function makeUser(Kiln $kiln, Role $role = Role::Viewer, array $attributes = []): User
{
    return User::factory()->inKiln($kiln, $role)->create($attributes);
}

/**
 * Log in as a user inside a kiln, the way EnsureKilnContext does for real requests
 * (Livewire::test() does not run route middleware).
 */
function actingInKiln(User $user, Kiln $kiln): User
{
    test()->actingAs($user);
    session()->put('kiln_id', $kiln->id);
    app(CurrentKiln::class)->set($kiln);
    $user->unsetRelation('roles')->unsetRelation('permissions');

    return $user;
}
