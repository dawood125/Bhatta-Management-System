<?php

namespace App\Accounting;

use App\Enums\Permission;
use App\Models\DayClose;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The day-close lock: once a day is closed, nothing new (money, stock or production) can be
 * dated on or before it, except by the owner (`days.reopen`), until the owner reopens it
 * (proposed default for Q-41). Checked by the journal poster and by the actions that move
 * bricks, so no form can get around it.
 */
class BooksLock
{
    /** Error key; forms show it as an error message (RunsActions). */
    public const ERROR_KEY = 'books_closed';

    /** The last closed day, or null while no day is closed. */
    public function closedThrough(): ?Carbon
    {
        $date = DayClose::query()->whereNull('reopened_at')->max('close_date');

        return $date === null ? null : Carbon::parse((string) $date)->startOfDay();
    }

    public function isClosed(CarbonInterface $date): bool
    {
        $through = $this->closedThrough();

        return $through !== null && $date->format('Y-m-d') <= $through->format('Y-m-d');
    }

    /** Refuse a date in a closed day, unless the actor may change closed days. */
    public function assertOpen(CarbonInterface $date, ?User $actor): void
    {
        if (! $this->isClosed($date) || $actor?->can(Permission::DaysReopen->value) === true) {
            return;
        }

        throw ValidationException::withMessages([self::ERROR_KEY => __('The books are closed up to :date. Only the owner can add entries on or before that day.', [
            'date' => $this->closedThrough()?->format('d-m-Y'),
        ])]);
    }
}
