<?php

use App\Backups\BackupRunner;
use App\Enums\Role;
use App\Livewire\Backups\BackupIndex;
use App\Models\AppSetting;
use App\Models\BackupRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner);
    actingInKiln($this->owner, $this->kiln);
    Storage::fake('backups');
    config(['services.google_drive.client_id' => 'test-client', 'services.google_drive.client_secret' => 'test-secret', 'services.google_drive.keep' => 30]);

    $this->google = ['token' => 'granted', 'copies' => 32];
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/device/code') => Http::response(['device_code' => 'device-1', 'user_code' => 'ABCD-EFGH', 'verification_url' => 'https://www.google.com/device', 'expires_in' => 1800, 'interval' => 5]),
            str_contains($url, 'oauth2.googleapis.com/token') => match ($this->google['token']) {
                'pending' => Http::response(['error' => 'authorization_pending'], 428),
                'denied' => Http::response(['error' => 'access_denied'], 403),
                'revoked' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400),
                default => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3599]),
            },
            str_contains($url, '/revoke') => Http::response([]),
            str_contains($url, '/drive/v3/about') => Http::response(['user' => ['emailAddress' => 'kiln.backups@gmail.com']]),
            str_contains($url, 'upload_id=') => Http::response(['id' => 'copy-new']),
            str_contains($url, '/upload/drive/v3/files') => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=up-1']),
            $request->method() === 'POST' && str_contains($url, '/drive/v3/files') => Http::response(['id' => 'folder-1']),
            $request->method() === 'GET' && str_contains($url, '/drive/v3/files') => Http::response(['files' => array_map(fn ($i) => ['id' => "copy-{$i}"], range(1, $this->google['copies']))]),
            $request->method() === 'DELETE' => Http::response([], 204),
            default => Http::response(['error' => ['message' => "Unexpected {$request->method()} {$url}"]], 500),
        };
    });
});

function connectedDrive(): AppSetting
{
    $settings = (new AppSetting)->forceFill(['id' => 1, 'google_drive_token' => 'refresh-1', 'google_drive_email' => 'kiln.backups@gmail.com', 'google_drive_folder_id' => 'folder-1', 'google_drive_connected_at' => now()]);
    $settings->save();

    return $settings;
}

it('connects with a code entered at google.com/device, seeing only its own folder', function () {
    $this->get('/backups')->assertSee('Connect Google Drive');
    $this->google['token'] = 'pending';

    $page = Livewire::test(BackupIndex::class)->call('connectGoogleDrive')
        ->assertSet('driveCode.user_code', 'ABCD-EFGH')->assertSee('ABCD-EFGH')->assertSee('google.com/device')
        ->call('checkGoogleDrive')->assertSet('driveCode.user_code', 'ABCD-EFGH');

    $this->google['token'] = 'granted';
    $page->call('checkGoogleDrive')->assertSet('driveCode', null)->assertDispatched('toast', type: 'success');

    $settings = AppSetting::current();
    expect($settings->hasGoogleDrive())->toBeTrue()
        ->and($settings->google_drive_token)->toBe('refresh-1')
        ->and(DB::table('app_settings')->value('google_drive_token'))->not->toContain('refresh-1')
        ->and($settings->google_drive_email)->toBe('kiln.backups@gmail.com')
        ->and($settings->google_drive_folder_id)->toBe('folder-1');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/device/code') && $request['scope'] === 'https://www.googleapis.com/auth/drive.file');
    $this->get('/backups')->assertSee('Connected to kiln.backups@gmail.com');
});

it('says so when access is not allowed', function () {
    $this->google['token'] = 'denied';
    Livewire::test(BackupIndex::class)->call('connectGoogleDrive')->call('checkGoogleDrive')
        ->assertSet('driveCode', null)->assertDispatched('toast', type: 'error', message: 'Access to Google Drive was not allowed.');
    expect(AppSetting::current()->hasGoogleDrive())->toBeFalse();
});

it('copies every new backup into its folder, keeping the newest 30 there', function () {
    connectedDrive();

    app(BackupRunner::class)->run($this->owner);

    $copy = BackupRun::query()->where('kind', BackupRun::COPY)->sole();
    expect($copy->succeeded())->toBeTrue((string) $copy->message)
        ->and(BackupRun::query()->where('kind', BackupRun::MANUAL)->sole()->succeeded())->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable')
        && $request['parents'] === ['folder-1'] && str_starts_with((string) $request['name'], 'bhatta-manager-'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_contains($request->url(), 'upload_id=up-1') && str_starts_with($request->body(), 'PK'));
    expect(Http::recorded(fn (Request $request) => $request->method() === 'DELETE')->map(fn ($pair) => basename($pair[0]->url()))->values()->all())->toBe(['copy-31', 'copy-32']);

    $this->get('/backups')->assertSee('Copy to Google Drive')->assertSee('Last copy:');
});

it('records a failed copy without failing the backup, and says how to fix it', function () {
    connectedDrive();
    $this->google['token'] = 'revoked';

    app(BackupRunner::class)->run($this->owner);

    expect(BackupRun::query()->where('kind', BackupRun::MANUAL)->sole()->succeeded())->toBeTrue();
    $copy = BackupRun::query()->where('kind', BackupRun::COPY)->sole();
    expect($copy->succeeded())->toBeFalse()->and($copy->message)->toContain('Connect Google Drive again');
    $this->get('/backups')->assertSee('The last copy failed');
});

it('disconnects, and is not offered without the Google sign-in details', function () {
    connectedDrive();
    Livewire::test(BackupIndex::class)->call('disconnectGoogleDrive')->assertDispatched('toast', type: 'success');
    expect(AppSetting::current()->hasGoogleDrive())->toBeFalse();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/revoke') && $request['token'] === 'refresh-1');

    config(['services.google_drive.client_id' => null]);
    $this->get('/backups')->assertSee('Not available on this server yet')->assertDontSee('Connect Google Drive');
});
