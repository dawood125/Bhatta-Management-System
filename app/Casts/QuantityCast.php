<?php

namespace App\Casts;

use App\Support\Quantity;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps a BIGINT column of thousandths to a Quantity object. Like MoneyCast, only Quantity (or
 * null) can be assigned, so units and thousandths can never be mixed up by accident.
 *
 * @implements CastsAttributes<Quantity, mixed>
 */
class QuantityCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Quantity
    {
        return $value === null ? null : Quantity::fromMilli((int) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Quantity) {
            throw new InvalidArgumentException("The [{$key}] attribute must be a Quantity instance.");
        }

        return $value->milli;
    }
}
