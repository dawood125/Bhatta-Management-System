<?php

namespace App\Jobs;

use App\Support\Health;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sent through the queue every five minutes: when the queue worker runs it, `kiln:check` knows
 * the worker is alive (invoice emails depend on it).
 */
class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Health::beat(Health::QUEUE);
    }
}
