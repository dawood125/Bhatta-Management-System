<?php

namespace App\Support\Mail;

use App\Models\AppSetting;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Throwable;

/**
 * Puts the email settings made in the app (Settings → Email) into the mail configuration, so
 * invoice emails and backup alerts go out through the kiln's own email server. Without them the
 * .env settings stay in use.
 */
class MailSettings
{
    public const MAILER = 'app_settings';

    public function apply(?AppSetting $settings = null): void
    {
        $settings ??= AppSetting::current();

        if ($settings->hasMailServer()) {
            $encryption = $settings->mail_encryption ?? 'tls';
            config([
                'mail.mailers.'.self::MAILER => [
                    'transport' => 'smtp',
                    'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                    'host' => $settings->mail_host,
                    'port' => $settings->mail_port ?? ($encryption === 'ssl' ? 465 : 587),
                    'username' => $settings->mail_username,
                    'password' => $settings->mail_password,
                    'timeout' => 30,
                ],
                'mail.default' => self::MAILER,
                'mail.from' => [
                    'address' => $settings->mail_from_address,
                    'name' => $settings->mail_from_name ?: config('app.name'),
                ],
            ]);
            app('mail.manager')->purge(self::MAILER);
        }

        $alerts = $settings->backupEmails();
        if ($alerts !== []) {
            $channel = ['mail'];
            config([
                'backup.notifications.mail.to' => $alerts,
                'backup.notifications.mail.from' => config('mail.from'),
                'backup.notifications.notifications.'.BackupHasFailedNotification::class => $channel,
                'backup.notifications.notifications.'.UnhealthyBackupWasFoundNotification::class => $channel,
                'backup.notifications.notifications.'.CleanupHasFailedNotification::class => $channel,
            ]);
        }
    }

    /** As apply(), but never fails (e.g. before the first migration). */
    public function applyQuietly(): void
    {
        try {
            $this->apply();
        } catch (Throwable) {
            // No settings table yet: keep the .env settings.
        }
    }
}
