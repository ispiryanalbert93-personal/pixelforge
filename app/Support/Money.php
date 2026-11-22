<?php

namespace App\Support;

use InvalidArgumentException;
use RoundingMode;

final class Money
{
    public const int NANODOLLARS_PER_USD = 1_000_000_000;

    public const int DEFAULT_MARKUP_PERCENT = 25;

    public static function normalizeNano(int|float|string $nano): string
    {
        if (is_int($nano)) {
            return (string) $nano;
        }

        if (is_float($nano)) {
            if (! is_finite($nano)) {
                throw new InvalidArgumentException('Nanodollar float value must be finite.');
            }

            return self::stripLeadingZeros(number_format($nano, 0, '.', ''));
        }

        $trimmed = trim($nano);

        if (! preg_match('/^[+-]?\d+$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid nanodollar integer string: [{$nano}].");
        }

        return self::stripLeadingZeros(ltrim($trimmed, '+'));
    }

    public static function toIntOrString(int|float|string $nano): int|string
    {
        $normalized = self::normalizeNano($nano);

        if (bccomp($normalized, (string) PHP_INT_MAX, 0) <= 0
            && bccomp($normalized, (string) PHP_INT_MIN, 0) >= 0) {
            return (int) $normalized;
        }

        return $normalized;
    }

    public static function toNumber(int|float|string $nano): int|float
    {
        $normalized = self::normalizeNano($nano);

        if (bccomp($normalized, (string) PHP_INT_MAX, 0) <= 0
            && bccomp($normalized, (string) PHP_INT_MIN, 0) >= 0) {
            return (int) $normalized;
        }

        return (float) $normalized;
    }

    public static function add(int|float|string $left, int|float|string $right): int|string
    {
        return self::toIntOrString(bcadd(self::normalizeNano($left), self::normalizeNano($right), 0));
    }

    public static function sub(int|float|string $left, int|float|string $right): int|string
    {
        return self::toIntOrString(bcsub(self::normalizeNano($left), self::normalizeNano($right), 0));
    }

    public static function negate(int|float|string $nano): int|string
    {
        return self::toIntOrString(bcsub('0', self::normalizeNano($nano), 0));
    }

    public static function compare(int|float|string $left, int|float|string $right): int
    {
        return bccomp(self::normalizeNano($left), self::normalizeNano($right), 0);
    }

    public static function maxZero(int|float|string $nano): int|string
    {
        $normalized = self::normalizeNano($nano);

        return bccomp($normalized, '0', 0) > 0 ? self::toIntOrString($normalized) : 0;
    }

    public static function nanoToUsd(int|float|string $nano): string
    {
        return bcdiv(self::normalizeNano($nano), (string) self::NANODOLLARS_PER_USD, 9);
    }

    public static function usdToNano(string $usd): int|string
    {
        $normalized = self::normalizeDecimalString($usd);
        $scaled = bcmul($normalized, (string) self::NANODOLLARS_PER_USD, 4);

        return self::toIntOrString(bcround($scaled, 0, RoundingMode::HalfAwayFromZero));
    }

    public static function applyMarkup(
        int|float|string $providerCostNano,
        int $markupPercent = self::DEFAULT_MARKUP_PERCENT,
    ): int|string {
        $multiplier = bcadd('100', (string) $markupPercent, 0);
        $numerator = bcmul(self::normalizeNano($providerCostNano), $multiplier, 0);
        $scaled = bcdiv($numerator, '100', 4);

        return self::toIntOrString(bcround($scaled, 0, RoundingMode::HalfAwayFromZero));
    }

    public static function ratePerMillionToNanoPerToken(string $rateUsdPerMillion): string
    {
        $normalized = self::normalizeDecimalString($rateUsdPerMillion);
        $nanoPerToken = bcmul($normalized, '1000', 6);

        return self::trimTrailingDecimalZeros($nanoPerToken);
    }

    public static function formatDisplay(int|float|string $nano, int $precision = 4): string
    {
        if ($precision < 0) {
            throw new InvalidArgumentException('Display precision must be non-negative.');
        }

        $usd = self::nanoToUsd($nano);
        $rounded = bcround($usd, $precision, RoundingMode::HalfAwayFromZero);
        $isNegative = bccomp($rounded, '0', $precision) < 0;
        $unsigned = ltrim($rounded, '-');

        [$integerPart, $fractionPart] = array_pad(explode('.', $unsigned, 2), 2, '');

        $groupedInteger = strrev(implode(',', str_split(strrev($integerPart), 3)));

        if ($precision === 0) {
            return ($isNegative ? '-' : '').'$'.$groupedInteger;
        }

        $minDecimals = min(2, $precision);
        $paddedFraction = str_pad($fractionPart, $precision, '0');
        $trimmedFraction = rtrim(substr($paddedFraction, $minDecimals), '0');
        $displayFraction = substr($paddedFraction, 0, $minDecimals).$trimmedFraction;

        return ($isNegative ? '-' : '').'$'.$groupedInteger.'.'.$displayFraction;
    }

    private static function stripLeadingZeros(string $integer): string
    {
        $stripped = preg_replace('/^(-?)0+(?=\d)/', '$1', $integer) ?? $integer;

        return $stripped === '-0' ? '0' : $stripped;
    }

    private static function normalizeDecimalString(string $value): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $trimmed)) {
            throw new InvalidArgumentException("Invalid numeric money string: [{$value}].");
        }

        return ltrim($trimmed, '+');
    }

    private static function trimTrailingDecimalZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim($value, '0');

        return rtrim($trimmed, '.');
    }
}
