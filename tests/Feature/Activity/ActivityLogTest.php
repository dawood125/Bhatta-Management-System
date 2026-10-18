<?php

use App\Enums\Role;
use App\Livewire\Activity\ActivityIndex;
use App\Models\Activity;
use App\Support\Kiln\CurrentKiln;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeKiln();
    $this->owner = makeUser($this->kiln, Role::Owner, ['name' => 'Owner One']);
});

it('is visible to owners and managers only', function (Role $role, bool $allowed) {
    actingInKiln(makeUser($this->kiln, $role), $this->kiln);

    $test = Livewire::test(ActivityIndex::class);

    $allowed ? $test->assertOk() : $test->assertForbidden();
})->with([
    [Role::Owner, true],
    [Role::Manager, true],
    [Role::Munshi, false],
    [Role::DataEntry, false],
    [Role::Viewer, false],
]);

it('shows only this kiln\'s history', function () {
    $otherKiln = makeKiln();
    app(CurrentKiln::class)->run($otherKiln, fn () => makeUser($otherKiln, Role::Viewer, ['name' => 'Secret Person']));
    makeUser($this->kiln, Role::Viewer, ['name' => 'Visible Person']);

    actingInKiln($this->owner, $this->kiln);

    Livewire::test(ActivityIndex::class)
        ->assertSee('Visible Person')
        ->assertDontSee('Secret Person');
});

it('filters by person and date', function () {
    actingInKiln($this->owner, $this->kiln);
    activity()->causedBy($this->owner)->event('logged_in')->log('logged_in');

    $old = Activity::query()->latest('id')->first();
    $old->forceFill(['created_at' => now()->subDays(10)])->save();

    $manager = makeUser($this->kiln, Role::Manager, ['name' => 'Manager Person']);
    actingInKiln($manager, $this->kiln);
    activity()->causedBy($manager)->event('logged_in')->log('logged_in');

    actingInKiln($this->owner, $this->kiln);

    Livewire::test(ActivityIndex::class)
        ->set('causerId', (string) $manager->id)
        ->tap(function ($test) use ($manager) {
            $rows = $test->instance()->activities;
            expect($rows->every(fn ($a) => $a->causer_id === $manager->id))->toBeTrue()
                ->and($rows->count())->toBeGreaterThan(0);
        })
        ->set('causerId', '')
        ->set('from', now()->subDays(2)->format('Y-m-d'))
        ->tap(function ($test) use ($old) {
            expect($test->instance()->activities->pluck('id'))->not->toContain($old->id);
        })
        ->call('clearFilters')
        ->assertSet('from', '')
        ->tap(function ($test) use ($old) {
            expect($test->instance()->activities->pluck('id'))->toContain($old->id);
        });
});

it('ignores malformed filter values instead of failing', function () {
    actingInKiln($this->owner, $this->kiln);

    Livewire::test(ActivityIndex::class)
        ->set('causerId', '1 OR 1=1')
        ->set('from', '2026-13-45')
        ->set('to', 'yesterday')
        ->assertOk();
});

it('never records passwords', function () {
    actingInKiln($this->owner, $this->kiln);
    $user = makeUser($this->kiln, Role::Viewer);
    $user->update(['password' => 'brandnew99', 'name' => 'Changed Name']);

    $json = Activity::query()->get()->toJson();

    expect($json)->not->toContain('brandnew99')
        ->and($json)->not->toContain('"password"')
        ->and($json)->not->toContain('remember_token');
});
