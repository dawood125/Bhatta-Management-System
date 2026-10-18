<?php

use App\Actions\Users\ChangeOwnPassword;
use App\Enums\Role;
use App\Livewire\Profile\ProfileEdit;
use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeKiln();
    $this->user = makeUser($this->kiln, Role::Munshi, ['name' => 'Ali', 'password' => 'oldpass12', 'locale' => 'en']);
    actingInKiln($this->user, $this->kiln);
});

it('shows the profile page', function () {
    $this->get('/profile')->assertOk()->assertSee('My profile')->assertSee('Ali');
});

it('updates own details', function () {
    Livewire::test(ProfileEdit::class)
        ->set('profile.name', 'Ali Raza')
        ->set('profile.phone', '0301 7654321')
        ->set('profile.email', 'ALI@Example.test')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $user = $this->user->fresh();
    expect($user->name)->toBe('Ali Raza')
        ->and($user->phone)->toBe('0301 7654321')
        ->and($user->email)->toBe('ali@example.test');
});

it('reloads the page when the language changes', function () {
    Livewire::test(ProfileEdit::class)
        ->set('profile.locale', 'ur')
        ->call('saveProfile')
        ->assertRedirect(route('profile.edit'));

    expect($this->user->fresh()->locale)->toBe('ur');
});

it('validates own details', function (string $field, string $value) {
    makeUser($this->kiln, Role::Viewer, ['email' => 'taken@example.test']);

    Livewire::test(ProfileEdit::class)
        ->set("profile.{$field}", $value)
        ->call('saveProfile')
        ->assertHasErrors("profile.{$field}");
})->with([
    'empty name' => ['name', ''],
    'email used by someone else' => ['email', 'taken@example.test'],
    'bad phone' => ['phone', 'call me'],
    'unknown language' => ['locale', 'de'],
]);

it('changes own password', function () {
    Livewire::test(ProfileEdit::class)
        ->set('passwordForm', ['current_password' => 'oldpass12', 'password' => 'newpass34', 'password_confirmation' => 'newpass34'])
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertSet('passwordForm.current_password', '')
        ->assertDispatched('toast');

    expect(Hash::check('newpass34', $this->user->fresh()->password))->toBeTrue()
        ->and(Activity::query()->where('event', 'password_changed')->where('causer_id', $this->user->id)->exists())->toBeTrue();
});

it('logs out the user\'s other devices but keeps this one', function () {
    DB::table('sessions')->insert([
        ['id' => 'this-device', 'user_id' => $this->user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()],
        ['id' => 'other-device', 'user_id' => $this->user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()],
    ]);

    app(ChangeOwnPassword::class)->handle(
        $this->user,
        ['current_password' => 'oldpass12', 'password' => 'newpass34', 'password_confirmation' => 'newpass34'],
        'this-device',
    );

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['this-device']);
});

it('requires the correct current password', function () {
    Livewire::test(ProfileEdit::class)
        ->set('passwordForm', ['current_password' => 'wrongpass1', 'password' => 'newpass34', 'password_confirmation' => 'newpass34'])
        ->call('changePassword')
        ->assertHasErrors('passwordForm.current_password');

    expect(Hash::check('oldpass12', $this->user->fresh()->password))->toBeTrue();
});

it('requires a new password that is different, strong and confirmed', function (array $form) {
    Livewire::test(ProfileEdit::class)
        ->set('passwordForm', array_merge(['current_password' => 'oldpass12'], $form))
        ->call('changePassword')
        ->assertHasErrors('passwordForm.password');
})->with([
    'same as current' => [['password' => 'oldpass12', 'password_confirmation' => 'oldpass12']],
    'too weak' => [['password' => 'abc', 'password_confirmation' => 'abc']],
    'not confirmed' => [['password' => 'newpass34', 'password_confirmation' => 'newpass35']],
]);
