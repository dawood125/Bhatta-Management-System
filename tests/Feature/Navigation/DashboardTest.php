<?php

use App\Enums\Role;

beforeEach(function () {
    $this->kiln = makeKiln(['name' => 'Demo Bhatta']);
});

it('greets the user and shows the kiln', function () {
    $owner = makeUser($this->kiln, Role::Owner, ['name' => 'Haji Sahib']);

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertSee('Haji Sahib')
        ->assertSee('Demo Bhatta')
        ->assertSee('Owner');
});

it('shows administration links only to people allowed to use them', function (Role $role, bool $seesAdmin) {
    $user = makeUser($this->kiln, $role);

    $response = $this->actingAs($user)->get('/dashboard')->assertOk();

    if ($seesAdmin) {
        $response->assertSee(route('users.index'))->assertSee(route('activity.index'));
    } else {
        $response->assertDontSee(route('users.index'))->assertDontSee(route('activity.index'));
    }
})->with([
    [Role::Owner, true],
    [Role::Manager, true],
    [Role::Munshi, false],
    [Role::DataEntry, false],
    [Role::Viewer, false],
]);

it('redirects the home page to the dashboard', function () {
    $this->actingAs(makeUser($this->kiln, Role::Viewer))->get('/')->assertRedirect('/dashboard');
});

it('shows the component gallery outside production only', function () {
    $this->actingAs(makeUser($this->kiln, Role::Viewer))->get('/dev/components')->assertOk();
});
