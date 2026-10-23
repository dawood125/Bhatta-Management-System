<?php

namespace App\Support;

/**
 * Stock that may never go below zero on any day. Used for materials and bricks.
 */
final class RunningStock
{
    /**
     * The lowest the stock reaches on the given day or any later day if $change is added on
     * that day. Below zero means the change would make some day's stock negative.
     *
     * @param  iterable<string, int>  $netByDay  net change per day ("Y-m-d" => amount), days in ascending order
     */
    public static function lowestFrom(iterable $netByDay, string $day, int $change): int
    {
        $running = 0;
        $lowest = null;

        foreach ($netByDay as $date => $net) {
            // The stock at the start of the first later day (with the change) also counts.
            if ($date > $day && $lowest === null) {
                $lowest = $running + $change;
            }

            $running += $net;

            if ($date >= $day) {
                $lowest = min($lowest ?? PHP_INT_MAX, $running + $change);
            }
        }

        return $lowest ?? $running + $change;
    }
}
