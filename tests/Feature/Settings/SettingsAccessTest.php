<?php

use App\Enums\Role;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
});

$pages = ['/settings/products', '/settings/units', '/settings/stages', '/settings/areas', '/settings/expense-categories'];

it('lets owners and managers open the settings lists', function (string $page) {
    foreach ([Role::Owner, Role::Manager] as $role) {
        $this->actingAs(makeUser($this->kiln, $role))->get($page)->assertOk();
    }
})->with($pages);

it('keeps other roles out of the settings lists', function (string $page) {
    foreach ([Role::Munshi, Role::DataEntry, Role::Viewer] as $role) {
        $this->actingAs(makeUser($this->kiln, $role))->get($page)->assertForbidden();
    }
})->with($pages);

it('lets only the owner edit the kiln profile', function () {
    $this->actingAs(makeUser($this->kiln, Role::Owner))->get('/settings/kiln')->assertOk();

    foreach ([Role::Manager, Role::Munshi, Role::DataEntry, Role::Viewer] as $role) {
        $this->actingAs(makeUser($this->kiln, $role))->get('/settings/kiln')->assertForbidden();
    }
});

it('opens the first settings page each person may use', function () {
    $this->actingAs(makeUser($this->kiln, Role::Owner))->get('/settings')->assertRedirect(route('settings.kiln'));
    $this->actingAs(makeUser($this->kiln, Role::Manager))->get('/settings')->assertRedirect(route('settings.products'));
    $this->actingAs(makeUser($this->kiln, Role::Viewer))->get('/settings')->assertForbidden();
});

it('shows the settings link and tabs only to people allowed to use them', function () {
    $this->actingAs(makeUser($this->kiln, Role::Manager))->get('/settings/products')
        ->assertSee(route('settings.products'))
        ->assertDontSee(route('settings.kiln'));

    $this->actingAs(makeUser($this->kiln, Role::Viewer))->get('/dashboard')
        ->assertDontSee(route('settings.index'));
});
