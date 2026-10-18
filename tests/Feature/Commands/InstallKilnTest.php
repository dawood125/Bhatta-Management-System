<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Kiln;
use App\Models\User;
use App\Support\Access\KilnAccess;
use App\Support\Access\PermissionSynchronizer;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

it('sets up a kiln and its owner, who can then log in', function () {
    $this->artisan('kiln:install', [
        '--kiln' => 'Al-Noor Bhatta',
        '--owner' => 'Haji Sahib',
        '--username' => 'HajiSahib',
        '--password' => 'strongpass1',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $kiln = Kiln::query()->where('name', 'Al-Noor Bhatta')->sole();
    $owner = User::query()->where('username', 'hajisahib')->sole();

    expect(app(KilnAccess::class)->roleIn($owner, $kiln))->toBe(Role::Owner);

    $this->post('/login', ['login' => 'hajisahib', 'password' => 'strongpass1'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($owner);
});

it('refuses invalid owner details and creates nothing', function (array $options) {
    makeUser(makeKiln(), Role::Viewer, ['username' => 'taken']);

    $this->artisan('kiln:install', array_merge([
        '--kiln' => 'New Kiln',
        '--owner' => 'Owner',
        '--username' => 'newowner',
        '--password' => 'strongpass1',
        '--no-interaction' => true,
    ], $options))->assertFailed();

    expect(Kiln::query()->where('name', 'New Kiln')->exists())->toBeFalse();
})->with([
    'username taken' => [['--username' => 'taken']],
    'weak password' => [['--password' => 'abc']],
    'bad username' => [['--username' => 'has space']],
    'bad email' => [['--email' => 'nope']],
]);

it('syncs permissions and roles idempotently', function () {
    app(PermissionSynchronizer::class)->sync();
    app(PermissionSynchronizer::class)->sync();
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(PermissionModel::query()->count())->toBe(count(Permission::cases()))
        ->and(RoleModel::query()->whereNull('kiln_id')->count())->toBe(count(Role::cases()));

    foreach (Role::cases() as $role) {
        $model = RoleModel::query()->whereNull('kiln_id')->where('name', $role->value)->sole();
        expect($model->permissions->pluck('name')->sort()->values()->all())
            ->toBe(collect($role->permissions())->map->value->sort()->values()->all());
    }
});

it('removes permissions that no longer exist in the code', function () {
    PermissionModel::findOrCreate('obsolete.permission', 'web');

    app(PermissionSynchronizer::class)->sync();

    expect(PermissionModel::query()->where('name', 'obsolete.permission')->exists())->toBeFalse();
});

it('gives the owner every permission', function () {
    expect(Role::Owner->permissions())->toBe(Permission::cases());
});
