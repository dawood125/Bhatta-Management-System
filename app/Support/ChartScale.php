<?php

namespace App\Support;

/**
 * A value axis that reads well: the top rounded up to a clean number (1, 2, 2.5 or 5 × 10ⁿ)
 * and evenly spaced ticks from zero.
 */
final class ChartScale
{
    /**
     * @param  int  $steps  about how many steps the axis should have (fewer when the data needs fewer)
     * @return list<int> tick values from 0 to the clean top, in the same unit as $max
     */
    public static function ticks(int $max, int $steps = 4): array
    {
        if ($max <= 0) {
            return [0];
        }

        $rough = $max / $steps;
        $magnitude = 10 ** (int) floor(log10($rough));
        $step = $magnitude;
        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($factor * $magnitude >= $rough) {
                $step = (int) round($factor * $magnitude);
                break;
            }
        }

        // Only as many steps as the data needs, so the tallest mark fills most of the height.
        $count = max(1, (int) ceil($max / $step));

        return array_map(fn (int $i) => $i * $step, range(0, $count));
    }

    /** A tick label without decimals: 250,000. */
    public static function label(int $paisa): string
    {
        return number_format(intdiv($paisa, 100));
    }
}
