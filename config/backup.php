<?php

use Illuminate\Support\Str;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
| Backups (M11, spatie/laravel-backup). Every night: the whole database and the uploaded files
| (kiln logo) in one zip, encrypted when BACKUP_ARCHIVE_PASSWORD is set. The code itself is in git;
| .env holds passwords and is kept apart (see docs/07-deployment.md).
|
| BACKUP_DISKS lists where copies go, e.g. "backups" (this server) or "backups,offsite" once an
| off-server disk is configured. BACKUP_MAIL_TO gets an email when a backup fails or is too old.
*/

$disks = array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_DISKS', 'backups')))));
$mailTo = array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_MAIL_TO', '')))));
$alert = $mailTo === [] ? [] : ['mail'];

return [

    'backup' => [
        'name' => env('BACKUP_NAME', Str::slug((string) env('APP_NAME', 'bhatta-manager'))),

        'source' => [
            'files' => [
                'include' => [
                    storage_path('app/public'),
                ],
                'exclude' => [],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => storage_path('app'),
            ],

            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => 'sql',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            'disks' => $disks,
            // A copy that cannot reach one disk still goes to the others.
            'continue_on_failure' => true,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        // AES-256 when set. Keep this password safe: without it a backup cannot be opened.
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',

        // Open the finished zip again to make sure it is complete.
        'verify_backup' => true,

        'tries' => 2,
        'retry_delay' => 30,
    ],

    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => $alert,
            UnhealthyBackupWasFoundNotification::class => $alert,
            CleanupHasFailedNotification::class => $alert,
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            'to' => $mailTo,
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Bhatta Manager'),
            ],
        ],

        'slack' => ['webhook_url' => '', 'channel' => null, 'username' => null, 'icon' => null],
        'discord' => ['webhook_url' => '', 'username' => '', 'avatar_url' => ''],
        'webhook' => ['url' => ''],
    ],

    'log_channel' => null,

    // A backup older than a day, or backups taking more than 5 GB, are reported as unhealthy.
    'monitor_backups' => [
        [
            'name' => env('BACKUP_NAME', Str::slug((string) env('APP_NAME', 'bhatta-manager'))),
            'disks' => $disks,
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    // Every backup for 7 days, then one a day for 30 days, one a week for 12 weeks, one a month
    // for a year and one a year for 5 years.
    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 30,
            'keep_weekly_backups_for_weeks' => 12,
            'keep_monthly_backups_for_months' => 12,
            'keep_yearly_backups_for_years' => 5,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],

        'tries' => 1,
        'retry_delay' => 0,
    ],

    // Monthly restore drill (kiln:backup-drill): the latest backup is restored into this separate,
    // empty database and compared with the live one. Leave empty to skip the drill.
    'drill_database' => env('BACKUP_DRILL_DATABASE'),

    // The database client that loads a backup back in (kiln:restore-backup and the drill).
    'restore_client' => env('BACKUP_RESTORE_CLIENT', 'mysql'),

];
