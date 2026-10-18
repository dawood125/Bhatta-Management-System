<?php

use App\Enums\Role;
use App\Models\User;

/*
 * Real-browser tests (Chromium via Playwright): the JavaScript parts that feature tests cannot
 * see - Alpine dialogs, Livewire round-trips, right-to-left layout and phone widths.
 */

beforeEach(function () {
    $this->kiln = makeKiln(['name' => 'Browser Test Kiln', 'name_ur' => 'براؤزر ٹیسٹ بھٹہ']);
    $this->owner = makeUser($this->kiln, Role::Owner, ['name' => 'Browser Owner', 'username' => 'bowner', 'password' => 'secret123']);
});

function logInAsOwner(bool $phone = false): mixed
{
    $page = visit('/login');

    return ($phone ? $page->on()->mobile() : $page)
        ->type('login', 'bowner')
        ->type('password', 'secret123')
        ->press('Log in')
        ->assertPathIs('/dashboard');
}

it('logs in and shows the dashboard without JavaScript errors', function () {
    logInAsOwner()
        ->assertSee('Browser Owner')
        ->assertSee('Browser Test Kiln')
        ->assertNoJavaScriptErrors();
});

it('shows a clear error for a wrong password', function () {
    visit('/login')
        ->type('login', 'bowner')
        ->type('password', 'wrong-pass1')
        ->press('Log in')
        ->assertPathIs('/login')
        ->assertSee('These credentials do not match our records.');
});

it('switches the whole interface to Urdu, right to left', function () {
    $page = logInAsOwner();

    $page->press('اردو')
        ->assertSee('ڈیش بورڈ')
        ->assertSee('براؤزر ٹیسٹ بھٹہ')
        ->assertAttribute('html', 'dir', 'rtl')
        ->assertNoJavaScriptErrors();

    expect($this->owner->fresh()->locale)->toBe('ur');
});

it('creates a user through the dialog', function () {
    $page = logInAsOwner();

    $page->navigate('/users')
        ->click('Add user')
        ->assertSee('The person will log in with this username and password.')
        ->type('#form-name', 'Browser Munshi')
        ->type('#form-username', 'bmunshi')
        ->select('#form-role', Role::Munshi->value)
        ->type('#form-password', 'abcd1234')
        ->type('#form-password-confirmation', 'abcd1234')
        ->click('Create user')
        ->assertSee('User account created.')
        ->assertSee('Browser Munshi')
        ->assertNoJavaScriptErrors();

    expect(User::query()->where('username', 'bmunshi')->exists())->toBeTrue();
});

it('shows validation errors inside the dialog', function () {
    $page = logInAsOwner();

    $page->navigate('/users')
        ->click('Add user')
        ->click('Create user')
        ->assertSee('The name field is required.')
        ->assertSee('The username field is required.');
});

it('sends a user who was deactivated meanwhile to the login page on their next action', function () {
    $page = logInAsOwner()->navigate('/users')->assertSee('Browser Owner');

    $this->owner->forceFill(['is_active' => false])->save();

    $page->type('#user-search', 'any')
        ->wait(2)
        ->assertPathIs('/login')
        ->assertSee('This account has been deactivated. Please contact the kiln owner.');
});

it('fits a phone screen without sideways scrolling', function (string $path) {
    $page = logInAsOwner(phone: true)->navigate($path);

    $overflow = $page->script('document.documentElement.scrollWidth - window.innerWidth');

    expect($overflow)->toBeLessThanOrEqual(0);
})->with(['/dashboard', '/users', '/activity', '/profile']);

it('opens the navigation drawer on a phone', function () {
    logInAsOwner(phone: true)
        ->click('Open menu')
        ->assertVisible('aside')
        ->click('Users')
        ->assertPathIs('/users');
});
