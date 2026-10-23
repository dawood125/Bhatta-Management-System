<?php

namespace App\Rules;

use App\Support\Quantity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * A quantity typed by a person ("1,250", "31.68"), with at most the unit's decimal places.
 * It must be more than zero.
 */
class QuantityAmount implements ValidationRule
{
    public function __construct(private readonly int $decimals = 3) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail(__('Enter a valid quantity.'));

            return;
        }

        try {
            $quantity = Quantity::fromInput($value, $this->decimals);
        } catch (InvalidArgumentException) {
            $fail($this->decimals === 0
                ? __('Enter a whole number.')
                : __('Enter a valid quantity with at most :decimals decimal places.', ['decimals' => $this->decimals]));

            return;
        }

        if (! $quantity->isPositive()) {
            $fail(__('The quantity must be more than zero.'));
        }
    }
}
