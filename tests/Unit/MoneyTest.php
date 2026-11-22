<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_nano_to_usd_formats_exact_nine_decimal_places(): void
    {
        $this->assertSame('0.000000000', Money::nanoToUsd(0));
        $this->assertSame('0.000000001', Money::nanoToUsd(1));
        $this->assertSame('0.046125000', Money::nanoToUsd(46_125_000));
        $this->assertSame('1.000000000', Money::nanoToUsd(1_000_000_000));
        $this->assertSame('12.345678901', Money::nanoToUsd(12_345_678_901));
    }

    public function test_nano_to_usd_handles_negative_values(): void
    {
        $this->assertSame('-0.000000001', Money::nanoToUsd(-1));
        $this->assertSame('-0.046125000', Money::nanoToUsd(-46_125_000));
        $this->assertSame('-1.000000000', Money::nanoToUsd(-1_000_000_000));
    }

    public function test_usd_to_nano_converts_exact_strings_to_integers(): void
    {
        $this->assertSame(0, Money::usdToNano('0'));
        $this->assertSame(0, Money::usdToNano('0.000000000'));
        $this->assertSame(1, Money::usdToNano('0.000000001'));
        $this->assertSame(46_125_000, Money::usdToNano('0.046125000'));
        $this->assertSame(5_000_000_000, Money::usdToNano('5.00'));
        $this->assertSame(12_500_000_000, Money::usdToNano('12.5'));
        $this->assertSame(-46_125_000, Money::usdToNano('-0.046125000'));
        $this->assertSame(1, Money::usdToNano('0.0000000005'));
        $this->assertSame(0, Money::usdToNano('0.0000000004'));
        $this->assertSame(-1, Money::usdToNano('-0.0000000005'));
    }

    public function test_usd_to_nano_rejects_invalid_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::usdToNano('not-a-number');
    }

    public function test_apply_markup_uses_exact_bcmath_arithmetic(): void
    {
        $this->assertSame(0, Money::applyMarkup(0));
        $this->assertSame(57_656_250, Money::applyMarkup(46_125_000));
        $this->assertSame(57_643_750, Money::applyMarkup(46_115_000));
        $this->assertSame(6_250, Money::applyMarkup(5_000));
        $this->assertSame(55_000, Money::applyMarkup(50_000, 10));
    }

    public function test_rate_per_million_to_nano_per_token_converts_accurately(): void
    {
        $this->assertSame('5000', Money::ratePerMillionToNanoPerToken('5.00'));
        $this->assertSame('10000', Money::ratePerMillionToNanoPerToken('10.00'));
        $this->assertSame('40000', Money::ratePerMillionToNanoPerToken('40.00'));
        $this->assertSame('12500', Money::ratePerMillionToNanoPerToken('12.50'));
        $this->assertSame('0.5', Money::ratePerMillionToNanoPerToken('0.0005'));
    }

    public function test_format_display_formats_usd_cleanly_for_ui(): void
    {
        $this->assertSame('$0.00', Money::formatDisplay(0));
        $this->assertSame('$0.0577', Money::formatDisplay(57_656_250));
        $this->assertSame('$10.00', Money::formatDisplay(10_000_000_000));
        $this->assertSame('$10.00', Money::formatDisplay(10_000_000_000, 2));
        $this->assertSame('$1,234.50', Money::formatDisplay(1_234_500_000_000));
        $this->assertSame('-$0.0577', Money::formatDisplay(-57_656_250));
        $this->assertSame('$0.00', Money::formatDisplay(-1));
    }

    public function test_string_and_float_nanodollars_preserve_exact_32bit_wasm_precision(): void
    {
        $this->assertSame('25000000000', Money::normalizeNano(25_000_000_000.0));
        $this->assertSame('25000000000', Money::normalizeNano('0025000000000'));
        $this->assertSame('25.000000000', Money::nanoToUsd('25000000000'));
        $this->assertSame('$25.00', Money::formatDisplay(25_000_000_000.0));
        $this->assertSame(1_000_000_000_000, Money::add('999000000000', '1000000000'));
        $this->assertSame(24_942_356_250, Money::sub('25000000000', '57643750'));
        $this->assertSame(1, Money::compare('25000000000', '3000000000'));
        $this->assertSame(0, Money::maxZero('-500'));
    }
}
