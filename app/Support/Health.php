<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Heartbeats of the scheduler and the queue worker on the live server, read by `kiln:check`.
 */
class Health
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    public static function beat(string $what): void
    {
        Cache::forever("health:{$what}", now()->getTimestamp());
    }

    public static function lastBeat(string $what): ?Carbon
    {
        $timestamp = Cache::get("health:{$what}");

        return is_int($timestamp) ? Carbon::createFromTimestamp($timestamp, config('app.timezone')) : null;
    }
}
