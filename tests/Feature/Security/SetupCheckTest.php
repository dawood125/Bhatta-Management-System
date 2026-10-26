<?php

use App\Enums\Role;
use App\Support\Health;
use App\Support\SetupCheck;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('backups');
});

it('checks the installation, and passes on a kiln with an owner', function () {
    makeUser(makeSeededKiln(['name' => 'Check Kiln']), Role::Owner);
    Health::beat(Health::QUEUE);

    $this->artisan('kiln:check')->assertSuccessful()
        ->expectsOutputToContain('Check Kiln')
        ->expectsOutputToContain('consistent')
        ->expectsOutputToContain('0 to fix.');

    $results = collect(app(SetupCheck::class)->run())->keyBy('check');
    expect($results['Queue worker']['status'])->toBe(SetupCheck::OK)
        ->and($results['Scheduler']['status'])->toBe(SetupCheck::WARN)
        ->and($results['Newest backup']['detail'])->toBe('none yet');
});

it('fails on a live server that shows error details, has no kiln and no backups', function () {
    app()['env'] = 'production';
    config(['app.debug' => true]);

    try {
        $results = collect(app(SetupCheck::class)->run())->keyBy('check');
    } finally {
        app()['env'] = 'testing';
    }

    expect($results['Debug mode']['status'])->toBe(SetupCheck::FAIL)
        ->and($results['Kiln and owner']['status'])->toBe(SetupCheck::FAIL)
        ->and($results['Newest backup']['status'])->toBe(SetupCheck::FAIL)
        ->and($results['Scheduler']['status'])->toBe(SetupCheck::FAIL);
});
