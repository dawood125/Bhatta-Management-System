<?php

namespace App\Actions\Sales\Concerns;

use App\Enums\PartyRole;
use App\Models\Party;
use App\Models\ProductionStage;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Shared checks of the sales actions: quantities typed by a person, the party in each role and
 * the lock that keeps two people from selling or moving the same bricks at the same time.
 */
trait ChecksSales
{
    /**
     * Lock the kiln's production stages: every action that changes brick stock (production and
     * sales alike) takes this lock first, so free stock can't be sold twice.
     */
    protected function lockStock(): void
    {
        ProductionStage::query()->orderBy('id')->lockForUpdate()->get();
    }

    /** A quantity typed by a person in a unit with $decimals decimal places; more than zero unless $allowZero. */
    protected function quantity(mixed $value, int $decimals, string $field, bool $allowZero = false): Quantity
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));

        if ($text === '' && $allowZero) {
            return Quantity::zero();
        }

        try {
            $quantity = Quantity::fromInput($text, $decimals);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => $decimals === 0
                ? __('Enter a whole number.')
                : __('Enter a valid quantity with at most :decimals decimal places.', ['decimals' => $decimals])]);
        }

        if ($quantity->isNegative() || ($quantity->isZero() && ! $allowZero)) {
            throw ValidationException::withMessages([$field => __('The quantity must be more than zero.')]);
        }

        return $quantity;
    }

    /** An active party of the kiln with the given role, or a message on the field. */
    protected function partyWithRole(mixed $id, PartyRole $role, string $field, string $message): Party
    {
        $party = Party::query()->where('is_active', true)->find((int) $id);

        if ($party === null || ! $party->hasRole($role)) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $party;
    }

    /** Nothing may be dated before the party's opening date (its statement starts there). */
    protected function assertAfterOpening(Party $party, CarbonInterface $date, string $field): void
    {
        if ($date->lt($party->opening_date)) {
            throw ValidationException::withMessages([$field => __('The date is before the opening date of :account (:date).', [
                'account' => $party->displayName(),
                'date' => $party->opening_date->format('d-m-Y'),
            ])]);
        }
    }
}
