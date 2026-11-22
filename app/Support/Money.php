<?php

namespace App\Support;

use InvalidArgumentException;
use RoundingMode;

final class Money
{
    public const int NANODOLLARS_PER_USD = 1_000_000_000;

    public const int DEFAULT_MARKUP_PERCENT = 25;

    public static function nanoToUsd(int $nano): string
    {
        return bcdiv((string) $nano, (string) self::NANODOLLARS_PER_USD, 9);
    }

    public static function usdToNano(string $usd): int
    {
        $normalized = self::normalizeDecimalString($usd);
        $scaled = bcmul($normalized, (string) self::NANODOLLARS_PER_USD, 4);

        return (int) bcround($scaled, 0, RoundingMode::HalfAwayFromZero);
    }

    public static function applyMarkup(
        int $providerCostNano,
        int $markupPercent = self::DEFAULT_MARKUP_PERCENT,
    ): int {
        $multiplier = bcadd('100', (string) $markupPercent, 0);
        $numerator = bcmul((string) $providerCostNano, $multiplier, 0);
        $scaled = bcdiv($numerator, '100', 4);

        return (int) bcround($scaled, 0, RoundingMode::HalfAwayFromZero);
    }

    public static function ratePerMillionToNanoPerToken(string $rateUsdPerMillion): string
    {
        $normalized = self::normalizeDecimalString($rateUsdPerMillion);
        $nanoPerToken = bcmul($normalized, '1000', 6);

        return self::trimTrailingDecimalZeros($nanoPerToken);
    }

    public static function formatDisplay(int $nano, int $precision = 4): string
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
