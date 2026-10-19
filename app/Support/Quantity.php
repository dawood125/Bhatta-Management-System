<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A quantity of material (tons, cubic feet, bags, ...), stored as whole thousandths so that
 * arithmetic is exact, the same way Money keeps paisa. A unit allows 0 to 3 decimal places.
 */
final class Quantity implements JsonSerializable, Stringable
{
    public const SCALE = 1000;

    /** Largest absolute quantity accepted: one trillion units. */
    public const MAX_MILLI = 1_000_000_000_000_000;

    private function __construct(public readonly int $milli)
    {
        if (abs($milli) > self::MAX_MILLI) {
            throw new InvalidArgumentException('Quantity is out of the supported range.');
        }
    }

    public static function fromMilli(int $milli): self
    {
        return new self($milli);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a quantity typed by a person, e.g. "1,250", "31.68". More decimal places than the
     * unit allows is rejected instead of being silently rounded.
     */
    public static function fromInput(string|int $value, int $decimals = 3): self
    {
        $decimals = max(0, min(3, $decimals));
        $clean = str_replace([',', ' '], '', trim((string) $value));
        $pattern = $decimals === 0 ? '/^-?\d+$/' : '/^-?\d+(\.\d{1,'.$decimals.'})?$/';

        if (! preg_match($pattern, $clean)) {
            throw new InvalidArgumentException("Invalid quantity [{$value}].");
        }

        try {
            $milli = BigDecimal::of($clean)->multipliedBy(self::SCALE)->toScale(0)->toBigInteger();
        } catch (MathException) {
            throw new InvalidArgumentException("Invalid quantity [{$value}].");
        }

        if ($milli->abs()->isGreaterThan(self::MAX_MILLI)) {
            throw new InvalidArgumentException('Quantity is out of the supported range.');
        }

        return new self($milli->toInt());
    }

    public function add(self $other): self
    {
        return new self($this->milli + $other->milli);
    }

    public function subtract(self $other): self
    {
        return new self($this->milli - $other->milli);
    }

    public function isZero(): bool
    {
        return $this->milli === 0;
    }

    public function isPositive(): bool
    {
        return $this->milli > 0;
    }

    public function isNegative(): bool
    {
        return $this->milli < 0;
    }

    public function equals(self $other): bool
    {
        return $this->milli === $other->milli;
    }

    public function greaterThan(self $other): bool
    {
        return $this->milli > $other->milli;
    }

    public function lessThan(self $other): bool
    {
        return $this->milli < $other->milli;
    }

    /** Exact decimal string with three places, e.g. "31.680" (for arithmetic with Money). */
    public function toDecimal(): string
    {
        $sign = $this->milli < 0 ? '-' : '';
        $abs = abs($this->milli);

        return $sign.intdiv($abs, self::SCALE).'.'.str_pad((string) ($abs % self::SCALE), 3, '0', STR_PAD_LEFT);
    }

    /** Plain decimal for form inputs, with the unit's decimal places, e.g. "31.68". */
    public function toInput(int $decimals): string
    {
        return str_replace(',', '', $this->format($decimals));
    }

    /**
     * Human format with thousands separators and the unit's decimal places, e.g. "1,250.50".
     * Never hides a digit: if the value has more decimals than the unit, they are shown.
     */
    public function format(int $decimals = 0): string
    {
        $sign = $this->milli < 0 ? '-' : '';
        $abs = abs($this->milli);
        $fraction = rtrim(str_pad((string) ($abs % self::SCALE), 3, '0', STR_PAD_LEFT), '0');
        $places = max(max(0, min(3, $decimals)), strlen($fraction));
        $fraction = str_pad($fraction, $places, '0');

        return $sign.number_format(intdiv($abs, self::SCALE)).($places > 0 ? '.'.$fraction : '');
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimal();
    }

    public function __toString(): string
    {
        return $this->format(3);
    }
}
