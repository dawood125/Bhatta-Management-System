<?php

namespace App\Actions\Settings;

use App\Actions\Settings\Concerns\ValidatesSettings;
use App\Enums\Permission;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\Mail\MailSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The email server of this installation (Settings → Email): invoice emails and backup alerts go
 * out through it. The password is kept when the field is left empty, and is stored encrypted.
 */
class SaveEmailSettings
{
    use ValidatesSettings;

    public const MAX_ALERT_ADDRESSES = 5;

    /**
     * @param  array<string, mixed>  $input  mail_host, mail_port, mail_encryption, mail_username, mail_password,
     *                                       remove_password, mail_from_address, mail_from_name, backup_email_to
     */
    public function handle(User $actor, array $input): AppSetting
    {
        $this->authorizeSettings($actor, Permission::KilnProfileManage);

        // The password is taken as typed: spaces can be part of it.
        $password = is_string($input['mail_password'] ?? null) ? $input['mail_password'] : '';
        $input = $this->clean(array_diff_key($input, ['mail_password' => true]), ['mail_host', 'mail_port', 'mail_username', 'mail_from_address', 'mail_from_name', 'backup_email_to']);
        $input['remove_password'] = filter_var($input['remove_password'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $input['mail_password'] = $password;

        $data = Validator::make($input, [
            'mail_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'mail_port' => ['nullable', 'required_with:mail_host', 'integer', 'between:1,65535'],
            'mail_encryption' => ['required', Rule::in(AppSetting::ENCRYPTIONS)],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'remove_password' => ['boolean'],
            'mail_from_address' => ['nullable', 'required_with:mail_host', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:100'],
            'backup_email_to' => ['nullable', 'string', 'max:500'],
        ], [
            'mail_host.regex' => __('Enter only the server name, e.g. smtp.gmail.com.'),
            'mail_port.required_with' => __('Enter the port of the email server.'),
            'mail_from_address.required_with' => __('Enter the address the emails come from.'),
        ], [
            'mail_host' => __('email server'),
            'mail_port' => __('port'),
            'mail_encryption' => __('security'),
            'mail_username' => __('username'),
            'mail_password' => __('password'),
            'mail_from_address' => __('from address'),
            'mail_from_name' => __('from name'),
            'backup_email_to' => __('email addresses'),
        ])->validate();

        $alerts = array_values(array_unique(array_filter(array_map(fn (string $email) => mb_strtolower(trim($email)), explode(',', (string) $data['backup_email_to'])))));
        foreach ($alerts as $address) {
            if (Validator::make(['email' => $address], ['email' => ['email']])->fails()) {
                throw ValidationException::withMessages(['backup_email_to' => __('":address" is not a valid email address.', ['address' => $address])]);
            }
        }
        if (count($alerts) > self::MAX_ALERT_ADDRESSES) {
            throw ValidationException::withMessages(['backup_email_to' => __('At most :max addresses.', ['max' => self::MAX_ALERT_ADDRESSES])]);
        }

        $settings = AppSetting::query()->find(1) ?? (new AppSetting)->forceFill(['id' => 1]);
        $settings->fill([
            'mail_host' => $data['mail_host'] !== null ? mb_strtolower($data['mail_host']) : null,
            'mail_port' => $data['mail_port'] !== null ? (int) $data['mail_port'] : null,
            'mail_encryption' => $data['mail_encryption'],
            'mail_username' => $data['mail_username'],
            'mail_from_address' => $data['mail_from_address'] !== null ? mb_strtolower($data['mail_from_address']) : null,
            'mail_from_name' => $data['mail_from_name'],
            'backup_email_to' => $alerts === [] ? null : implode(', ', $alerts),
            'updated_by' => $actor->id,
        ]);
        if ($password !== '') {
            $settings->mail_password = $password;
        } elseif ($data['remove_password'] || $data['mail_host'] === null) {
            $settings->mail_password = null;
        }
        $settings->save();

        activity()->causedBy($actor)->performedOn($settings)->event('email_settings_saved')
            ->withProperties(['server' => $settings->mail_host, 'from' => $settings->mail_from_address, 'backup_alerts' => $settings->backup_email_to])
            ->log('email_settings_saved');

        app(MailSettings::class)->apply($settings);

        return $settings;
    }
}
