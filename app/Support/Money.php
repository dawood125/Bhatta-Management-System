<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of Pakistani rupees, stored as whole paisa (1 PKR = 100 paisa).
 *
 * All arithmetic is integer/exact decimal. Floats are never used for money.
 * Rounding happens in exactly one place (timesQuantity) and is half-up (away from zero).
 */
final class Money implements JsonSerializable, Stringable
{
    /** Largest absolute amount accepted: 10 trillion rupees, far above any kiln's figures. */
    public const MAX_PAISA = 1_000_000_000_000_000;

    private function __construct(public readonly int $paisa)
    {
        if (abs($paisa) > self::MAX_PAISA) {
            throw new InvalidArgumentException('Amount is out of the supported range.');
        }
    }

    public static function fromPaisa(int $paisa): self
    {
        return new self($paisa);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a rupee amount typed by a person, e.g. "1,250", "1250.5", "-300.75".
     * More than 2 decimal places is rejected instead of being silently rounded.
     */
    public static function fromRupees(string|int $amount): self
    {
        $clean = str_replace([',', ' '], '', trim((string) $amount));

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $clean)) {
            throw new InvalidArgumentException("Invalid rupee amount [{$amount}].");
        }

        try {
            $paisa = BigDecimal::of($clean)->multipliedBy(100)->toScale(0)->toBigInteger();
        } catch (MathException) {
            throw new InvalidArgumentException("Invalid rupee amount [{$amount}].");
        }

        if ($paisa->abs()->isGreaterThan(self::MAX_PAISA)) {
            throw new InvalidArgumentException('Amount is out of the supported range.');
        }

        return new self($paisa->toInt());
    }

    /**
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount->paisa;
        }

        return new self($total);
    }

    public function add(self $other): self
    {
        return new self($this->paisa + $other->paisa);
    }

    public function subtract(self $other): self
    {
        return new self($this->paisa - $other->paisa);
    }

    public function negate(): self
    {
        return new self(-$this->paisa);
    }

    public function abs(): self
    {
        return new self(abs($this->paisa));
    }

    /**
     * Treat this amount as a rate "per $per units" and price $quantity units.
     *
     * Example: PKR 1,200 per 1,000 bricks × 11,500 bricks = PKR 13,800.00
     *   Money::fromRupees('1200')->timesQuantity(11500, per: 1000)
     *
     * $quantity may be a decimal string (e.g. "31.680" tons). The result is rounded once,
     * half-up to the nearest paisa.
     */
    public function timesQuantity(int|string $quantity, int $per = 1): self
    {
        if ($per <= 0) {
            throw new InvalidArgumentException('The rate basis must be a positive number.');
        }

        try {
            $paisa = BigDecimal::of($this->paisa)
                ->multipliedBy(BigDecimal::of((string) $quantity))
                ->dividedBy($per, 0, RoundingMode::HalfUp)
                ->toBigInteger();
        } catch (MathException) {
            throw new InvalidArgumentException("Invalid quantity [{$quantity}].");
        }

        if ($paisa->abs()->isGreaterThan(self::MAX_PAISA)) {
            throw new InvalidArgumentException('Amount is out of the supported range.');
        }

        return new self($paisa->toInt());
    }

    public function isZero(): bool
    {
        return $this->paisa === 0;
    }

    public function isPositive(): bool
    {
        return $this->paisa > 0;
    }

    public function isNegative(): bool
    {
        return $this->paisa < 0;
    }

    public function equals(self $other): bool
    {
        return $this->paisa === $other->paisa;
    }

    public function greaterThan(self $other): bool
    {
        return $this->paisa > $other->paisa;
    }

    public function lessThan(self $other): bool
    {
        return $this->paisa < $other->paisa;
    }

    /** Plain decimal string for form inputs and storage-agnostic exchange, e.g. "-1234.50". */
    public function toDecimal(): string
    {
        $sign = $this->paisa < 0 ? '-' : '';
        $abs = abs($this->paisa);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Human format with thousands separators, e.g. "1,234.50" or "-1,234.50". */
    public function format(): string
    {
        $sign = $this->paisa < 0 ? '-' : '';
        $abs = abs($this->paisa);

        return $sign.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Format with the currency label, e.g. "Rs 1,234.50". */
    public function formatWithCurrency(): string
    {
        return __('Rs').' '.$this->format();
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimal();
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
