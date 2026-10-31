<?php

namespace App\Support;

/**
 * Decimal-safe money helpers. Amounts travel as strings ("450.00"), never floats.
 */
final class Money
{
    public static function add(string|int|float $a, string|int|float $b): string
    {
        return bcadd((string) $a, (string) $b, 2);
    }

    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return bcsub((string) $a, (string) $b, 2);
    }

    public static function mul(string|int|float $amount, int $quantity): string
    {
        return bcmul((string) $amount, (string) $quantity, 2);
    }

    /** Turn whatever the database returned (string, int or float) into "123.45". */
    public static function from(string|int|float|null $value): string
    {
        return bcadd(number_format((float) ($value ?? 0), 2, '.', ''), '0', 2);
    }

    /** @param iterable<string|int|float> $amounts */
    public static function sum(iterable $amounts): string
    {
        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }

    /** A percentage of an amount, rounded half up to 2 decimals: percentOf('450.00', '5') = '22.50'. */
    public static function percentOf(string|int|float $amount, string|int|float $percent): string
    {
        $raw = bcdiv(bcmul((string) $amount, (string) $percent, 6), '100', 6);

        // bcadd truncates, so adding half a cent first rounds half up.
        return bcadd(bcadd($raw, '0.005', 3), '0', 2);
    }

    /** -1, 0 or 1 */
    public static function cmp(string|int|float $a, string|int|float $b): int
    {
        return bccomp((string) $a, (string) $b, 2);
    }

    public static function gte(string|int|float $a, string|int|float $b): bool
    {
        return self::cmp($a, $b) >= 0;
    }

    public static function gt(string|int|float $a, string|int|float $b): bool
    {
        return self::cmp($a, $b) > 0;
    }

    public static function isZero(string|int|float $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /** Whole-number percentage saved, e.g. market 520, price 450 => 13. */
    public static function percentOff(string|int|float $market, string|int|float $price): int
    {
        if (bccomp((string) $market, '0', 2) <= 0) {
            return 0;
        }

        $off = bcdiv(bcmul(bcsub((string) $market, (string) $price, 2), '100', 4), (string) $market, 4);

        return max(0, (int) round((float) $off));
    }

    /** "Le 1,250.00" */
    public static function format(string|int|float|null $amount): string
    {
        return config('wibuy.currency_symbol', 'Le').' '.number_format((float) ($amount ?? 0), 2);
    }
}
