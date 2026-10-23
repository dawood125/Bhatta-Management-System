<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * A rupee amount typed by a person ("13,800", "1250.50"). By default it must be more than zero.
 */
class MoneyAmount implements ValidationRule
{
    public function __construct(
        private readonly bool $allowZero = false,
        private readonly bool $allowNegative = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail(__('Enter a valid amount, e.g. 13,800 or 1250.50.'));

            return;
        }

        try {
            $money = Money::fromRupees($value);
        } catch (InvalidArgumentException) {
            $fail(__('Enter a valid amount, e.g. 13,800 or 1250.50.'));

            return;
        }

        if ($money->isNegative() && ! $this->allowNegative) {
            $fail(__('The amount cannot be negative.'));
        } elseif ($money->isZero() && ! $this->allowZero) {
            $fail(__('The amount must be more than zero.'));
        }
    }
}
