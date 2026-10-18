<?php

use App\Actions\Users\SaveUser;
use App\Enums\Role;
use App\Livewire\Users\UserIndex;
use App\Models\Activity;
use App\Models\User;
use App\Support\Access\KilnAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeKiln();
    $this->owner = makeUser($this->kiln, Role::Owner, ['name' => 'Owner One', 'username' => 'owner1']);
    $this->manager = makeUser($this->kiln, Role::Manager, ['name' => 'Manager One', 'username' => 'manager1']);
    $this->munshi = makeUser($this->kiln, Role::Munshi, ['name' => 'Munshi One', 'username' => 'munshi1']);
});

function validUserInput(array $overrides = []): array
{
    return array_merge([
        'name' => 'New Person',
        'username' => 'newperson',
        'email' => '',
        'phone' => '0300 1234567',
        'locale' => 'ur',
        'role' => Role::DataEntry->value,
        'password' => 'abcd1234',
        'password_confirmation' => 'abcd1234',
    ], $overrides);
}

function roleOf(User $user, $kiln): ?Role
{
    return app(KilnAccess::class)->roleIn($user->fresh(), $kiln);
}

function fakeSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
    ]);
}

describe('access to the users page', function () {
    it('is open to owners and managers', function (string $who) {
        actingInKiln($this->{$who}, $this->kiln);

        Livewire::test(UserIndex::class)->assertOk()->assertSee('Owner One');
    })->with(['owner', 'manager']);

    it('is forbidden for other roles', function (Role $role) {
        actingInKiln(makeUser($this->kiln, $role), $this->kiln);

        Livewire::test(UserIndex::class)->assertForbidden();
    })->with([Role::Munshi, Role::DataEntry, Role::Viewer]);

    it('returns 403 for the page route as well', function () {
        $this->actingAs($this->munshi)->get('/users')->assertForbidden();
        $this->actingAs($this->owner)->get('/users')->assertOk();
    });

    it('only lists users of the current kiln', function () {
        $otherKiln = makeKiln();
        makeUser($otherKiln, Role::Owner, ['name' => 'Stranger Owner']);

        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->assertSee('Manager One')
            ->assertDontSee('Stranger Owner');
    });

    it('treats a user of another kiln as not found', function (string $method) {
        $stranger = makeUser(makeKiln(), Role::Viewer);
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call($method, $stranger->id)
            ->assertNotFound()
            ->assertSet('showForm', false)
            ->assertSet('showPasswordForm', false)
            ->assertSet('showStatusConfirm', false);
    })->with(['edit', 'openPasswordReset', 'confirmStatusChange']);

    it('searches and filters', function () {
        $this->munshi->update(['is_active' => false]);
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->set('search', 'manager1')
            ->assertSee('Manager One')->assertDontSee('Munshi One')
            ->set('search', '')
            ->set('status', 'inactive')
            ->assertSee('Munshi One')->assertDontSee('Manager One');
    });

    it('ignores sorting by columns that are not allowed', function () {
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('sortBy', 'password')
            ->assertSet('sortField', '')
            ->call('sortBy', 'username')
            ->assertSet('sortField', 'username')
            ->assertSet('sortDirection', 'asc')
            ->call('sortBy', 'username')
            ->assertSet('sortDirection', 'desc');
    });
});

describe('creating users', function () {
    it('creates a user with a role in this kiln', function () {
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('create')
            ->set('form', validUserInput(['username' => '  NewPerson ']))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertDispatched('toast');

        $user = User::query()->where('username', 'newperson')->sole();

        expect($user->name)->toBe('New Person')
            ->and($user->locale)->toBe('ur')
            ->and($user->email)->toBeNull()
            ->and(Hash::check('abcd1234', $user->password))->toBeTrue()
            ->and($user->belongsToKiln($this->kiln))->toBeTrue()
            ->and(roleOf($user, $this->kiln))->toBe(Role::DataEntry);
    });

    it('records the creation and the role in the activity log, without the password', function () {
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)->set('form', validUserInput())->call('save')->assertHasNoErrors();

        $user = User::query()->where('username', 'newperson')->sole();
        $entries = Activity::query()->where('subject_id', $user->id)->where('subject_type', $user->getMorphClass())->get();

        expect($entries->pluck('event')->all())->toContain('created', 'role_changed')
            ->and($entries->every(fn ($e) => $e->kiln_id === $this->kiln->id))->toBeTrue()
            ->and($entries->every(fn ($e) => $e->causer_id === $this->owner->id))->toBeTrue()
            ->and($entries->toJson())->not->toContain('abcd1234')
            ->and($entries->toJson())->not->toContain('password');
    });

    it('validates the input', function (array $input, string $errorField) {
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->set('form', validUserInput($input))
            ->call('save')
            ->assertHasErrors("form.{$errorField}");

        expect(User::query()->where('name', 'New Person')->exists())->toBeFalse();
    })->with([
        'name missing' => [['name' => ''], 'name'],
        'username missing' => [['username' => ''], 'username'],
        'username too short' => [['username' => 'ab'], 'username'],
        'username with spaces inside' => [['username' => 'new person'], 'username'],
        'username in Urdu letters' => [['username' => 'علی'], 'username'],
        'username taken' => [['username' => 'manager1'], 'username'],
        'email invalid' => [['email' => 'not-an-email'], 'email'],
        'phone with letters' => [['phone' => '0300-abc'], 'phone'],
        'unknown role' => [['role' => 'superadmin'], 'role'],
        'unknown language' => [['locale' => 'fr'], 'locale'],
        'password too short' => [['password' => 'ab12', 'password_confirmation' => 'ab12'], 'password'],
        'password without numbers' => [['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'], 'password'],
        'password without letters' => [['password' => '12345678', 'password_confirmation' => '12345678'], 'password'],
        'password not confirmed' => [['password_confirmation' => 'different1'], 'password'],
    ]);

    it('lets only an owner create another owner', function () {
        actingInKiln($this->manager, $this->kiln);

        Livewire::test(UserIndex::class)
            ->assertDontSee('value="owner"', false)
            ->set('form', validUserInput(['role' => Role::Owner->value]))
            ->call('save')
            ->assertHasErrors('form.role');

        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->set('form', validUserInput(['role' => Role::Owner->value]))
            ->call('save')
            ->assertHasNoErrors();
    });

    it('refuses the action itself for users without permission, whatever the screen does', function () {
        actingInKiln($this->munshi, $this->kiln);

        app(SaveUser::class)->handle($this->munshi, null, validUserInput());
    })->throws(AuthorizationException::class);
});

describe('editing users', function () {
    it('updates details and role', function () {
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('edit', $this->munshi->id)
            ->assertSet('form.username', 'munshi1')
            ->assertSet('form.role', Role::Munshi->value)
            ->set('form.name', 'Munshi Renamed')
            ->set('form.role', Role::Manager->value)
            ->call('save')
            ->assertHasNoErrors();

        expect($this->munshi->fresh()->name)->toBe('Munshi Renamed')
            ->and(roleOf($this->munshi, $this->kiln))->toBe(Role::Manager)
            ->and(Activity::query()->where('event', 'role_changed')->where('subject_id', $this->munshi->id)->exists())->toBeTrue();
    });

    it('does not change the password when editing', function () {
        $hash = $this->munshi->password;
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('edit', $this->munshi->id)
            ->set('form.name', 'Changed')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->munshi->fresh()->password)->toBe($hash);
    });

    it('does not let a manager edit an owner', function () {
        actingInKiln($this->manager, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('edit', $this->owner->id)
            ->set('form.name', 'Hacked')
            ->call('save')
            ->assertHasErrors('form.role');

        expect($this->owner->fresh()->name)->toBe('Owner One');
    });

    it('does not let anyone change their own role', function () {
        actingInKiln($this->manager, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('edit', $this->manager->id)
            ->set('form.role', Role::Viewer->value)
            ->call('save')
            ->assertHasErrors('form.role');

        expect(roleOf($this->manager, $this->kiln))->toBe(Role::Manager);
    });
});

describe('the last active owner rule', function () {
    it('refuses to demote the only active owner', function () {
        // An inactive owner can still be the actor in the action (as owner role) - simulate a second owner
        // who is active only while acting, then deactivated, to isolate the rule.
        $peer = makeUser($this->kiln, Role::Owner);
        actingInKiln($peer, $this->kiln);

        DB::table('users')->where('id', $peer->id)->update(['is_active' => false]);

        Livewire::test(UserIndex::class)
            ->call('edit', $this->owner->id)
            ->set('form.role', Role::Manager->value)
            ->call('save')
            ->assertHasErrors(['form.role' => 'The kiln must keep at least one active owner.']);

        expect(roleOf($this->owner, $this->kiln))->toBe(Role::Owner);
    });

    it('refuses to deactivate the only active owner', function () {
        $peer = makeUser($this->kiln, Role::Owner);
        actingInKiln($peer, $this->kiln);
        DB::table('users')->where('id', $peer->id)->update(['is_active' => false]);

        Livewire::test(UserIndex::class)
            ->call('confirmStatusChange', $this->owner->id)
            ->call('changeStatus')
            ->assertHasErrors(['user' => 'The kiln must keep at least one active owner.']);

        expect($this->owner->fresh()->is_active)->toBeTrue();
    });
});

describe('activating and deactivating', function () {
    it('deactivates a user and logs them out everywhere', function () {
        fakeSession($this->munshi, 'munshi-phone');
        fakeSession($this->munshi, 'munshi-office');
        fakeSession($this->manager, 'manager-session');
        $rememberToken = $this->munshi->remember_token;
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('confirmStatusChange', $this->munshi->id)
            ->assertSet('showStatusConfirm', true)
            ->call('changeStatus')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $munshi = $this->munshi->fresh();
        expect($munshi->is_active)->toBeFalse()
            ->and($munshi->remember_token)->not->toBe($rememberToken)
            ->and(DB::table('sessions')->where('user_id', $this->munshi->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $this->manager->id)->count())->toBe(1);
    });

    it('reactivates a user', function () {
        $this->munshi->update(['is_active' => false]);
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('confirmStatusChange', $this->munshi->id)
            ->call('changeStatus')
            ->assertHasNoErrors();

        expect($this->munshi->fresh()->is_active)->toBeTrue();
    });

    it('does not let users deactivate themselves', function () {
        actingInKiln($this->manager, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('confirmStatusChange', $this->manager->id)
            ->call('changeStatus')
            ->assertHasErrors('user');

        expect($this->manager->fresh()->is_active)->toBeTrue();
    });

    it('does not let a manager deactivate an owner', function () {
        actingInKiln($this->manager, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('confirmStatusChange', $this->owner->id)
            ->call('changeStatus')
            ->assertHasErrors('user');

        expect($this->owner->fresh()->is_active)->toBeTrue();
    });
});

describe('resetting passwords', function () {
    it('sets a new password and logs the user out of all devices', function () {
        fakeSession($this->munshi, 'munshi-phone');
        actingInKiln($this->manager, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('openPasswordReset', $this->munshi->id)
            ->set('passwordForm', ['password' => 'newpass99', 'password_confirmation' => 'newpass99'])
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertSet('showPasswordForm', false);

        expect(Hash::check('newpass99', $this->munshi->fresh()->password))->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $this->munshi->id)->count())->toBe(0)
            ->and(Activity::query()->where('event', 'password_reset')->where('subject_id', $this->munshi->id)->exists())->toBeTrue();
    });

    it('validates the new password', function () {
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('openPasswordReset', $this->munshi->id)
            ->set('passwordForm', ['password' => 'short', 'password_confirmation' => 'short'])
            ->call('resetPassword')
            ->assertHasErrors('passwordForm.password');
    });

    it('does not let a manager reset an owner\'s password', function () {
        $hash = $this->owner->password;
        actingInKiln($this->manager, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('openPasswordReset', $this->owner->id)
            ->set('passwordForm', ['password' => 'newpass99', 'password_confirmation' => 'newpass99'])
            ->call('resetPassword')
            ->assertHasErrors('passwordForm.password');

        expect($this->owner->fresh()->password)->toBe($hash);
    });

    it('sends users to their profile to change their own password', function () {
        actingInKiln($this->owner, $this->kiln);

        Livewire::test(UserIndex::class)
            ->call('openPasswordReset', $this->owner->id)
            ->set('passwordForm', ['password' => 'newpass99', 'password_confirmation' => 'newpass99'])
            ->call('resetPassword')
            ->assertHasErrors('passwordForm.password');
    });
});
