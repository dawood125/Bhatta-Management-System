<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps a BIGINT paisa column to a Money object.
 *
 * Only Money (or null) can be assigned; plain numbers are rejected so rupees and paisa
 * can never be mixed up by accident.
 *
 * Assignment accepts anything at the type level and validates at runtime, because the whole
 * point of the cast is to reject wrong input (e.g. a plain int) instead of trusting it.
 *
 * @implements CastsAttributes<Money, mixed>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::fromPaisa((int) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("The [{$key}] attribute must be a Money instance.");
        }

        return $value->paisa;
    }
}
