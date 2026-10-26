<?php

namespace App\Livewire\Settings;

use App\Actions\Settings\SaveEmailSettings;
use App\Actions\Settings\SendTestEmail;
use App\Enums\Permission;
use App\Livewire\Concerns\RunsActions;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings → Email (owner): the email server that sends invoice emails and backup alerts, who
 * gets the backup alerts, and a test email. Set here, not in .env, so each kiln sets its own.
 */
class EmailSettings extends Component
{
    use RunsActions;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $hasPassword = false;

    public string $testTo = '';

    public function mount(): void
    {
        $this->authorize(Permission::KilnProfileManage->value);
        $this->fillForm(AppSetting::current());
        $this->testTo = (string) $this->actor()->email;
    }

    /** Gmail: the server details; the password must be an app password of the Google account. */
    public function useGmail(): void
    {
        $this->form['mail_host'] = 'smtp.gmail.com';
        $this->form['mail_port'] = '587';
        $this->form['mail_encryption'] = 'tls';
    }

    public function save(SaveEmailSettings $saveEmailSettings): void
    {
        $settings = $this->runAction(fn () => $saveEmailSettings->handle($this->actor(), $this->form), 'form');
        $this->fillForm($settings);
        $this->toast(__('Email settings saved.'));
    }

    public function sendTest(SendTestEmail $sendTestEmail): void
    {
        $this->runAction(fn () => $sendTestEmail->handle($this->actor(), ['to' => $this->testTo]));
        $this->toast(__('Test email sent to :address. Check that it arrived (also in spam).', ['address' => $this->testTo]));
    }

    public function render(): View
    {
        return view('livewire.settings.email-settings', [
            'configured' => AppSetting::current()->hasMailServer(),
        ])->title(__('Email'));
    }

    private function fillForm(AppSetting $settings): void
    {
        $this->form = [
            'mail_host' => (string) $settings->mail_host,
            'mail_port' => $settings->mail_port !== null ? (string) $settings->mail_port : '587',
            'mail_encryption' => $settings->mail_encryption ?? 'tls',
            'mail_username' => (string) $settings->mail_username,
            'mail_password' => '',
            'remove_password' => false,
            'mail_from_address' => (string) $settings->mail_from_address,
            'mail_from_name' => (string) $settings->mail_from_name,
            'backup_email_to' => (string) $settings->backup_email_to,
        ];
        $this->hasPassword = filled($settings->mail_password);
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
