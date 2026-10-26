<?php

use App\Enums\Role;
use App\Jobs\RecordQueueHeartbeat;
use App\Livewire\Settings\EmailSettings;
use App\Mail\TestMail;
use App\Models\Activity;
use App\Models\AppSetting;
use App\Support\Mail\MailSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->kiln = makeSeededKiln();
    $this->owner = makeUser($this->kiln, Role::Owner, ['email' => 'owner@example.com']);
    actingInKiln($this->owner, $this->kiln);
});

function fillGmail($component, array $extra = [])
{
    return $component->call('useGmail')
        ->set('form.mail_username', 'kiln.test@gmail.com')
        ->set('form.mail_password', 'abcd efgh ijkl mnop')
        ->set('form.mail_from_address', 'Kiln.Test@gmail.com')
        ->set('form.mail_from_name', 'Test Kiln')
        ->set('form.backup_email_to', 'owner@example.com, partner@example.com')
        ->tap(fn ($c) => collect($extra)->each(fn ($value, $key) => $c->set($key, $value)));
}

it('is in the kiln settings for the owner only', function () {
    $this->get('/settings/email')->assertOk()->assertSee('Email server')->assertSee('Not set up yet');
    $this->get('/settings/kiln')->assertSee(route('settings.email'), false);

    $this->actingAs(makeUser($this->kiln, Role::Manager))->get('/settings/email')->assertForbidden();
});

it('saves the email server with the password encrypted, and uses it for every email', function () {
    fillGmail(Livewire::test(EmailSettings::class))->call('save')->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success')
        ->assertSet('hasPassword', true)
        ->assertSet('form.mail_password', '');

    $settings = AppSetting::current();
    expect($settings->mail_host)->toBe('smtp.gmail.com')
        ->and($settings->mail_password)->toBe('abcd efgh ijkl mnop')
        ->and(DB::table('app_settings')->value('mail_password'))->not->toContain('abcd')
        ->and($settings->mail_from_address)->toBe('kiln.test@gmail.com')
        ->and(config('mail.default'))->toBe(MailSettings::MAILER)
        ->and(config('mail.mailers.'.MailSettings::MAILER))->toMatchArray(['host' => 'smtp.gmail.com', 'port' => 587, 'scheme' => 'smtp', 'username' => 'kiln.test@gmail.com', 'password' => 'abcd efgh ijkl mnop'])
        ->and(config('mail.from'))->toBe(['address' => 'kiln.test@gmail.com', 'name' => 'Test Kiln'])
        ->and(config('backup.notifications.mail.to'))->toBe(['owner@example.com', 'partner@example.com'])
        ->and(Activity::query()->where('event', 'email_settings_saved')->sole()->properties->toArray())->not->toHaveKey('password');

    // The password is never sent back to the browser; an empty field keeps it.
    $this->get('/settings/email')->assertDontSee('abcd efgh ijkl mnop');
    Livewire::test(EmailSettings::class)->set('form.mail_from_name', 'Renamed')->call('save')->assertHasNoErrors();
    expect(AppSetting::current()->mail_password)->toBe('abcd efgh ijkl mnop');

    Livewire::test(EmailSettings::class)->set('form.remove_password', true)->call('save');
    expect(AppSetting::current()->mail_password)->toBeNull();
});

it('checks the settings before saving them', function () {
    Livewire::test(EmailSettings::class)
        ->set('form.mail_host', 'https://smtp.gmail.com')
        ->set('form.mail_port', '')
        ->set('form.backup_email_to', 'owner@example.com, not-an-address')
        ->call('save')
        ->assertHasErrors(['form.mail_host', 'form.mail_port', 'form.mail_from_address']);

    Livewire::test(EmailSettings::class)->set('form.backup_email_to', 'owner@example.com, not-an-address')->call('save')
        ->assertHasErrors(['form.backup_email_to']);
    expect(AppSetting::query()->count())->toBe(0);
});

it('sends a test email with the saved settings', function () {
    Mail::fake();
    fillGmail(Livewire::test(EmailSettings::class))->call('save');

    Livewire::test(EmailSettings::class)->set('testTo', 'partner@example.com')->call('sendTest')
        ->assertHasNoErrors()->assertDispatched('toast', type: 'success');
    Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo('partner@example.com'));
});

it('shows the mail server\'s own error when the test email cannot be sent', function () {
    Livewire::test(EmailSettings::class)
        ->set('form.mail_host', '127.0.0.1')->set('form.mail_port', '1')->set('form.mail_encryption', 'none')
        ->set('form.mail_from_address', 'kiln@example.com')
        ->call('save')->assertHasNoErrors()
        ->call('sendTest')
        ->assertHasErrors(['to'])
        ->assertSee('The email could not be sent');
});

it('applies the settings in every queue job, so the worker never needs a restart', function () {
    fillGmail(Livewire::test(EmailSettings::class))->call('save');
    config(['mail.default' => 'array', 'mail.from.address' => 'old@example.com']);

    dispatch(new RecordQueueHeartbeat);

    expect(config('mail.default'))->toBe(MailSettings::MAILER)->and(config('mail.from.address'))->toBe('kiln.test@gmail.com');
});
