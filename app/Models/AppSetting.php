<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Settings of the whole installation (one row): the email server, backup alerts and the Google
 * Drive connection. Passwords and tokens are encrypted with the application key.
 *
 * @property int $id
 * @property string|null $mail_host
 * @property int|null $mail_port
 * @property string|null $mail_encryption
 * @property string|null $mail_username
 * @property string|null $mail_password
 * @property string|null $mail_from_address
 * @property string|null $mail_from_name
 * @property string|null $backup_email_to
 * @property string|null $google_drive_token
 * @property string|null $google_drive_email
 * @property string|null $google_drive_folder_id
 * @property Carbon|null $google_drive_connected_at
 * @property int|null $updated_by
 */
class AppSetting extends Model
{
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    protected $guarded = ['id'];

    protected $hidden = ['mail_password', 'google_drive_token'];

    protected function casts(): array
    {
        return [
            'mail_port' => 'integer',
            'mail_password' => 'encrypted',
            'google_drive_token' => 'encrypted',
            'google_drive_connected_at' => 'datetime',
        ];
    }

    /** The settings row, or empty settings when none were saved yet (nothing is written). */
    public static function current(): self
    {
        return self::query()->find(1) ?? new self;
    }

    public function hasMailServer(): bool
    {
        return filled($this->mail_host) && filled($this->mail_from_address);
    }

    /** @return list<string> */
    public function backupEmails(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->backup_email_to))));
    }

    public function hasGoogleDrive(): bool
    {
        return filled($this->google_drive_token) && filled($this->google_drive_folder_id);
    }
}
