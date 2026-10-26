<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Support\Health;
use Illuminate\Support\Facades\Schedule;

/*
| The live server runs `php artisan schedule:run` every minute (cron, see docs/07-deployment.md).
| Times are kiln time (APP_TIMEZONE).
*/

// Backups: old ones cleared first, then tonight's, checked in the morning; restore drill monthly.
Schedule::command('backup:clean')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('09:00');
Schedule::command('kiln:backup-drill')->monthlyOn(1, '03:00')->withoutOverlapping();

// Failed queue jobs (e.g. an invoice email that could not be sent) are kept for 30 days.
Schedule::command('queue:prune-failed', ['--hours' => 720])->weekly();

// Heartbeats for `kiln:check`: the scheduler runs, and the queue worker picks up jobs.
Schedule::call(fn () => Health::beat(Health::SCHEDULER))->everyFiveMinutes()->name('heartbeat');
Schedule::job(new RecordQueueHeartbeat)->everyFiveMinutes();
