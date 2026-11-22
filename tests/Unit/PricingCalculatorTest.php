<?php

namespace Tests\Unit;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\WalletTransactionType;
use App\Services\Pixelforge\PricingCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PricingCalculatorTest extends TestCase
{
    private const array SAMPLE_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private PricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new PricingCalculator;
    }

    public function test_generation_status_transitions_are_strictly_monotonic(): void
    {
        $this->assertFalse(GenerationStatus::Queued->isTerminal());
        $this->assertFalse(GenerationStatus::Processing->isTerminal());
        $this->assertTrue(GenerationStatus::Succeeded->isTerminal());
        $this->assertTrue(GenerationStatus::Failed->isTerminal());

        $this->assertFalse(GenerationStatus::Queued->canTransitionTo(GenerationStatus::Queued));
        $this->assertTrue(GenerationStatus::Queued->canTransitionTo(GenerationStatus::Processing));
        $this->assertTrue(GenerationStatus::Queued->canTransitionTo(GenerationStatus::Succeeded));
        $this->assertTrue(GenerationStatus::Queued->canTransitionTo(GenerationStatus::Failed));

        $this->assertFalse(GenerationStatus::Processing->canTransitionTo(GenerationStatus::Queued));
        $this->assertFalse(GenerationStatus::Processing->canTransitionTo(GenerationStatus::Processing));
        $this->assertTrue(GenerationStatus::Processing->canTransitionTo(GenerationStatus::Succeeded));
        $this->assertTrue(GenerationStatus::Processing->canTransitionTo(GenerationStatus::Failed));

        foreach (GenerationStatus::cases() as $target) {
            $this->assertFalse(GenerationStatus::Succeeded->canTransitionTo($target));
            $this->assertFalse(GenerationStatus::Failed->canTransitionTo($target));
        }
    }

    public function test_enums_have_expected_backed_values_and_helpers(): void
    {
        $this->assertSame('1024x1024', ImageSize::Square->value);
        $this->assertSame('1536x1024', ImageSize::Landscape->value);
        $this->assertSame('1024x1536', ImageSize::Portrait->value);
        $this->assertFalse(ImageSize::Square->isWideOrTall());
        $this->assertTrue(ImageSize::Landscape->isWideOrTall());
        $this->assertTrue(ImageSize::Portrait->isWideOrTall());
        $this->assertNotEmpty(ImageSize::Square->label());

        $this->assertSame('low', ImageQuality::Low->value);
        $this->assertSame('medium', ImageQuality::Medium->value);
        $this->assertSame('high', ImageQuality::High->value);
        $this->assertSame('Medium', ImageQuality::Medium->label());

        $this->assertSame('topup', WalletTransactionType::TopUp->value);
        $this->assertSame('hold', WalletTransactionType::Hold->value);
        $this->assertSame('release', WalletTransactionType::Release->value);
        $this->assertSame('charge', WalletTransactionType::Charge->value);
    }

    public function test_unicode_prompt_text_token_estimation(): void
    {
        $this->assertSame(0, $this->calculator->estimateTextTokens(''));
        $this->assertSame(1, $this->calculator->estimateTextTokens('a'));
        $this->assertSame(1, $this->calculator->estimateTextTokens('abcd'));
        $this->assertSame(2, $this->calculator->estimateTextTokens('abcde'));

        // Accented characters (each is 1 UTF-8 codepoint, multi-byte in raw bytes)
        $this->assertSame(1, $this->calculator->estimateTextTokens('éàüö'));
        $this->assertSame(2, $this->calculator->estimateTextTokens('éàüöñ'));

        // Multi-byte emojis (each is 1 UTF-8 codepoint, 3-4 bytes in raw UTF-8)
        $this->assertSame(1, $this->calculator->estimateTextTokens('🎨🌄✨🚀'));
        $this->assertSame(2, $this->calculator->estimateTextTokens('🎨🌄✨🚀🔥'));
    }

    public function test_estimate_requires_complete_pricing_config(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->estimate(
            prompt: 'test',
            size: ImageSize::Square,
            quality: ImageQuality::Low,
            referenceImageCount: 0,
            pricingConfig: ['rates' => ['output_tokens' => '50.00']],
        );
    }

    public function test_estimate_calculates_min_expected_and_max_costs_from_live_config(): void
    {
        // 80 UTF-8 characters => estimated = 20, min = floor(20 * 0.85) = 17, max = ceil(20 * 1.15) = 23
        $prompt = str_repeat('a', 80);

        $estimate = $this->calculator->estimate(
            prompt: $prompt,
            size: ImageSize::Landscape,
            quality: ImageQuality::High,
            referenceImageCount: 2,
            pricingConfig: $this->samplePricingConfig(),
        );

        $this->assertSame(20, $estimate->estimatedTextTokens);
        $this->assertSame(17, $estimate->minTextTokens);
        $this->assertSame(23, $estimate->maxTextTokens);
        $this->assertSame(1530, $estimate->inputImageTokens);
        $this->assertSame(6450, $estimate->outputTokens);
        $this->assertSame(1000, $estimate->minReasoningTokens);
        $this->assertSame(4500, $estimate->midpointReasoningTokens);
        $this->assertSame(8000, $estimate->maxReasoningTokens);

        // Min provider: 17*5000 + 1530*10000 + 6450*40000 + 1000*12500 = 285,885,000
        $this->assertSame(285_885_000, $estimate->minProviderCostNano);
        $this->assertSame(357_356_250, $estimate->minUserCostNano);

        // Expected provider: 20*5000 + 1530*10000 + 6450*40000 + 4500*12500 = 329,650,000
        $this->assertSame(329_650_000, $estimate->expectedProviderCostNano);
        $this->assertSame(412_062_500, $estimate->expectedUserCostNano);

        // Max provider: 23*5000 + 1530*10000 + 6450*40000 + 8000*12500 = 373,415,000
        $this->assertSame(373_415_000, $estimate->maxProviderCostNano);
        $this->assertSame(466_768_750, $estimate->maxUserCostNano);

        $array = $estimate->toArray();
        $this->assertSame(412_062_500, $array['user_cost_nano']['expected']);
        $this->assertSame('0.466768750', $array['user_cost_usd']['max']);
    }

    public function test_estimate_rejects_invalid_reference_image_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->estimate('hello', ImageSize::Square, ImageQuality::Low, 5, $this->samplePricingConfig());
    }

    public function test_calculate_actual_rejects_negative_token_counts_and_missing_rates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculateActual([
            'input_text_tokens' => -1,
            'output_tokens' => 280,
        ], self::SAMPLE_RATES);
    }

    public function test_calculate_actual_requires_rates_when_not_failed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculateActual([
            'input_text_tokens' => 18,
            'output_tokens' => 1100,
        ], []);
    }

    public function test_calculate_actual_matches_pixelforge_docs_example(): void
    {
        $breakdown = $this->calculator->calculateActual([
            'input_text_tokens' => 18,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 162,
        ], self::SAMPLE_RATES);

        // 18*5000 + 0*10000 + 1100*40000 + 162*12500 = 46,115,000
        $this->assertSame(46_115_000, $breakdown->providerCostNano);
        $this->assertSame(57_643_750, $breakdown->userCostNano);
        $this->assertSame(11_528_750, $breakdown->profitNano);

        $array = $breakdown->toArray();
        $this->assertSame('0.046115000', $array['provider_cost_usd']);
        $this->assertSame('0.057643750', $array['user_cost_usd']);
        $this->assertSame('0.011528750', $array['profit_usd']);
    }

    public function test_calculate_actual_with_reference_images_and_custom_locked_rates(): void
    {
        $customRates = [
            'input_text_tokens' => '6.00',
            'input_image_tokens' => '12.00',
            'output_tokens' => '45.00',
            'reasoning_tokens' => '15.00',
        ];

        $breakdown = $this->calculator->calculateActual([
            'input_text_tokens' => 25,
            'input_image_tokens' => 1530,
            'output_tokens' => 1650,
            'reasoning_tokens' => 400,
        ], $customRates);

        // 25*6000 + 1530*12000 + 1650*45000 + 400*15000 = 98,760,000
        $this->assertSame(98_760_000, $breakdown->providerCostNano);
        $this->assertSame(123_450_000, $breakdown->userCostNano);
        $this->assertSame(24_690_000, $breakdown->profitNano);
        $this->assertSame($customRates, $breakdown->rates);
    }

    public function test_failed_generation_actual_cost_is_always_zero(): void
    {
        $breakdown = $this->calculator->calculateActual([
            'input_text_tokens' => 18,
            'input_image_tokens' => 1530,
            'output_tokens' => 1100,
            'reasoning_tokens' => 162,
        ], self::SAMPLE_RATES, failed: true);

        $this->assertSame(0, $breakdown->providerCostNano);
        $this->assertSame(0, $breakdown->userCostNano);
        $this->assertSame(0, $breakdown->profitNano);
        $this->assertSame('0.000000000', $breakdown->toArray()['user_cost_usd']);
    }

    /**
     * @return array<string, mixed>
     */
    private function samplePricingConfig(): array
    {
        return [
            'rates' => self::SAMPLE_RATES,
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'tokens_per_reference_image' => 765,
            'max_reference_images' => 4,
            'markup_percent' => 25,
            'qualities' => [
                [
                    'value' => 'low',
                    'label' => 'Low',
                    'reasoning_tokens' => ['min' => 0, 'midpoint' => 250, 'max' => 500],
                    'output_tokens' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                ],
                [
                    'value' => 'medium',
                    'label' => 'Medium',
                    'reasoning_tokens' => ['min' => 200, 'midpoint' => 1100, 'max' => 2000],
                    'output_tokens' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                ],
                [
                    'value' => 'high',
                    'label' => 'High',
                    'reasoning_tokens' => ['min' => 1000, 'midpoint' => 4500, 'max' => 8000],
                    'output_tokens' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
            ],
        ];
    }
}
