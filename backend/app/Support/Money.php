<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Fixed-precision helpers for the application's two-decimal money columns.
 *
 * Business calculations use integer minor units so PHP binary floating-point
 * values never decide whether a balance is paid, overpaid, or still due.
 */
final class Money
{
    private static function decimalInput(mixed $value): string
    {
        if (is_float($value)) {
            return number_format($value, 10, '.', '');
        }

        return trim((string) $value);
    }

    public static function minor(mixed $value): int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            $value = number_format($value, 10, '.', '');
        }

        $value = trim((string) $value);
        if (preg_match('/[eE]/', $value)) {
            $value = number_format((float) $value, 10, '.', '');
        }
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Invalid money value.');
        }

        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $minor = ((int) $matches[2] * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $minor++;
        }

        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    public static function decimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function normalize(mixed $value): string
    {
        return self::decimal(self::minor($value));
    }

    /** Normalize non-money decimal values such as exchange rates. */
    public static function normalizeDecimal(mixed $value, int $scale = 8): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Decimal scale must not be negative.');
        }

        return (string) BigDecimal::of(self::decimalInput($value))
            ->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function add(mixed ...$values): string
    {
        return self::decimal(array_sum(array_map(fn (mixed $value): int => self::minor($value), $values)));
    }

    public static function subtract(mixed $left, mixed $right): string
    {
        return self::decimal(self::minor($left) - self::minor($right));
    }

    public static function compare(mixed $left, mixed $right): int
    {
        return self::minor($left) <=> self::minor($right);
    }

    public static function compareDecimal(mixed $left, mixed $right): int
    {
        return BigDecimal::of(self::decimalInput($left))
            ->compareTo(BigDecimal::of(self::decimalInput($right)));
    }

    public static function minimum(mixed $left, mixed $right): string
    {
        return self::compare($left, $right) <= 0 ? self::normalize($left) : self::normalize($right);
    }

    public static function maximum(mixed $left, mixed $right): string
    {
        return self::compare($left, $right) >= 0 ? self::normalize($left) : self::normalize($right);
    }

    public static function multiply(mixed $amount, mixed $multiplier, int $scale = 2): string
    {
        return (string) BigDecimal::of(self::decimalInput($amount))
            ->multipliedBy(BigDecimal::of(self::decimalInput($multiplier)))
            ->toScale($scale, RoundingMode::HALF_UP);
    }

    /** Multiply several exact decimal values and round only once at the end. */
    public static function product(array $values, int $scale = 2): string
    {
        $result = BigDecimal::one();
        foreach ($values as $value) {
            $result = $result->multipliedBy(BigDecimal::of(self::decimalInput($value)));
        }

        return (string) $result->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function divide(mixed $amount, mixed $divisor, int $scale = 2): string
    {
        return (string) BigDecimal::of(self::decimalInput($amount))
            ->dividedBy(BigDecimal::of(self::decimalInput($divisor)), $scale, RoundingMode::HALF_UP);
    }
}
