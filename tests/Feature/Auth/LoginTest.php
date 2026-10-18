<?php

use App\Enums\Role;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->kiln = makeKiln();
    $this->user = makeUser($this->kiln, Role::Munshi, [
        'username' => 'ali.munshi',
        'email' => 'ali@example.test',
        'password' => 'secret123',
    ]);
});

it('shows the login page to guests', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Username or email')
        ->assertSee('<html lang="en" dir="ltr">', false);
});

it('sends guests to the login page', function (string $url) {
    $this->get($url)->assertRedirect(route('login'));
})->with(['/dashboard', '/users', '/activity', '/profile', '/']);

it('logs in with the username', function () {
    $this->post('/login', ['login' => 'ali.munshi', 'password' => 'secret123'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($this->user);
    expect($this->user->fresh()->last_login_at)->not->toBeNull();
});

it('logs in with the email, ignoring letter case', function () {
    $this->post('/login', ['login' => 'ALI@Example.TEST', 'password' => 'secret123'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($this->user);
});

it('records the login in the kiln activity log', function () {
    $this->post('/login', ['login' => 'ali.munshi', 'password' => 'secret123']);

    $entry = Activity::query()->where('event', 'logged_in')->sole();

    expect($entry->kiln_id)->toBe($this->kiln->id)
        ->and($entry->causer_id)->toBe($this->user->id);
});

it('rejects a wrong password or unknown user with the same message', function (string $login, string $password) {
    $this->from('/login')->post('/login', ['login' => $login, 'password' => $password])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['login' => __('auth.failed')]);

    $this->assertGuest();
})->with([
    'wrong password' => ['ali.munshi', 'wrong-pass1'],
    'unknown user' => ['nobody', 'secret123'],
]);

it('refuses deactivated accounts', function () {
    $this->user->update(['is_active' => false]);

    $this->from('/login')->post('/login', ['login' => 'ali.munshi', 'password' => 'secret123'])
        ->assertSessionHasErrors(['login' => 'This account has been deactivated. Please contact the kiln owner.']);

    $this->assertGuest();
});

it('refuses accounts that have no kiln access', function () {
    User::factory()->create(['username' => 'loner', 'password' => 'secret123']);

    $this->from('/login')->post('/login', ['login' => 'loner', 'password' => 'secret123'])
        ->assertSessionHasErrors(['login' => 'This account has no access to any kiln.']);

    $this->assertGuest();
});

it('refuses accounts whose only kiln is inactive', function () {
    $this->kiln->update(['is_active' => false]);

    $this->from('/login')->post('/login', ['login' => 'ali.munshi', 'password' => 'secret123'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('blocks login for a while after 5 wrong attempts', function () {
    RateLimiter::clear('ali.munshi|127.0.0.1');

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['login' => 'ali.munshi', 'password' => 'wrong-pass1']);
    }

    $this->from('/login')->post('/login', ['login' => 'ali.munshi', 'password' => 'secret123'])
        ->assertStatus(429);

    $this->assertGuest();
});

it('logs out', function () {
    $this->actingAs($this->user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});

it('logs out a user who is deactivated while logged in', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();

    $this->user->update(['is_active' => false]);

    $this->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('answers 401 to a background (Livewire) request from a deactivated user and keeps the reason for the login page', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();
    $this->user->update(['is_active' => false]);

    $this->withHeader('X-Livewire', 'true')->get('/dashboard')->assertStatus(401);
    $this->assertGuest();

    $this->get('/login')->assertSee('This account has been deactivated. Please contact the kiln owner.');
});

it('logs out a user who loses access to the kiln while logged in', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();

    DB::table('kiln_user')->where('user_id', $this->user->id)->delete();

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('ignores a kiln in the session that the user does not belong to', function () {
    $otherKiln = makeKiln(['name' => 'Someone else\'s kiln']);

    $this->actingAs($this->user)
        ->withSession(['kiln_id' => $otherKiln->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee($this->kiln->name)
        ->assertDontSee('Someone else\'s kiln')
        ->assertSessionHas('kiln_id', $this->kiln->id);
});
