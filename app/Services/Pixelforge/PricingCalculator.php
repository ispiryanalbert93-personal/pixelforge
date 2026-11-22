<?php

namespace App\Services\Pixelforge;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\DTO\ActualCostBreakdown;
use App\Services\Pixelforge\DTO\PricingEstimate;
use App\Support\Money;
use InvalidArgumentException;
use RoundingMode;

final class PricingCalculator
{
    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * @var list<string>
     */
    private const array RATE_KEYS = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    public function estimateTextTokens(string $prompt, int $charactersPerTextToken = 4): int
    {
        if ($charactersPerTextToken <= 0) {
            throw new InvalidArgumentException("Characters per text token must be positive, got {$charactersPerTextToken}.");
        }

        $length = mb_strlen($prompt, 'UTF-8');

        if ($length === 0) {
            return 0;
        }

        return intdiv($length + $charactersPerTextToken - 1, $charactersPerTextToken);
    }

    /**
     * @param  array<string, mixed>  $pricingConfig
     */
    public function estimate(
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
        array $pricingConfig,
    ): PricingEstimate {
        if ($referenceImageCount < 0 || $referenceImageCount > self::MAX_REFERENCE_IMAGES) {
            throw new InvalidArgumentException(
                sprintf('Reference image count must be between 0 and %d, got %d.', self::MAX_REFERENCE_IMAGES, $referenceImageCount)
            );
        }

        $resolvedRates = $this->requireRates($pricingConfig['rates'] ?? []);
        $charsPerToken = (int) ($pricingConfig['characters_per_text_token'] ?? 0);
        $variance = (float) ($pricingConfig['text_token_variance'] ?? -1);
        $tokensPerRefImage = (int) ($pricingConfig['tokens_per_reference_image'] ?? -1);
        $markupPercent = (int) ($pricingConfig['markup_percent'] ?? Money::DEFAULT_MARKUP_PERCENT);

        if ($charsPerToken <= 0 || $variance < 0 || $variance >= 1 || $tokensPerRefImage < 0) {
            throw new InvalidArgumentException('Pricing configuration is missing required token parameters.');
        }

        $qualityConfig = $this->findQualityConfig($pricingConfig['qualities'] ?? [], $quality);
        $outputTokens = (int) ($qualityConfig['output_tokens'][$size->value] ?? -1);
        $minReasoning = (int) ($qualityConfig['reasoning_tokens']['min'] ?? -1);
        $midpointReasoning = (int) ($qualityConfig['reasoning_tokens']['midpoint'] ?? -1);
        $maxReasoning = (int) ($qualityConfig['reasoning_tokens']['max'] ?? -1);

        if ($outputTokens < 0 || $minReasoning < 0 || $midpointReasoning < $minReasoning || $maxReasoning < $midpointReasoning) {
            throw new InvalidArgumentException("Pricing configuration is missing token bounds for [{$quality->value}] / [{$size->value}].");
        }

        $estimatedTextTokens = $this->estimateTextTokens($prompt, $charsPerToken);
        [$minTextTokens, $maxTextTokens] = $this->applyTextTokenVariance($estimatedTextTokens, $variance);

        $inputImageTokens = $referenceImageCount * $tokensPerRefImage;

        $minProviderCostNano = $this->calculateProviderCostNano(
            $minTextTokens,
            $inputImageTokens,
            $outputTokens,
            $minReasoning,
            $resolvedRates,
        );

        $expectedProviderCostNano = $this->calculateProviderCostNano(
            $estimatedTextTokens,
            $inputImageTokens,
            $outputTokens,
            $midpointReasoning,
            $resolvedRates,
        );

        $maxProviderCostNano = $this->calculateProviderCostNano(
            $maxTextTokens,
            $inputImageTokens,
            $outputTokens,
            $maxReasoning,
            $resolvedRates,
        );

        return new PricingEstimate(
            estimatedTextTokens: $estimatedTextTokens,
            minTextTokens: $minTextTokens,
            maxTextTokens: $maxTextTokens,
            inputImageTokens: $inputImageTokens,
            outputTokens: $outputTokens,
            minReasoningTokens: $minReasoning,
            midpointReasoningTokens: $midpointReasoning,
            maxReasoningTokens: $maxReasoning,
            minProviderCostNano: $minProviderCostNano,
            expectedProviderCostNano: $expectedProviderCostNano,
            maxProviderCostNano: $maxProviderCostNano,
            minUserCostNano: Money::applyMarkup($minProviderCostNano, $markupPercent),
            expectedUserCostNano: Money::applyMarkup($expectedProviderCostNano, $markupPercent),
            maxUserCostNano: Money::applyMarkup($maxProviderCostNano, $markupPercent),
            rates: $resolvedRates,
        );
    }

    /**
     * @param  array{input_text_tokens?: int, input_image_tokens?: int, output_tokens?: int, reasoning_tokens?: int}  $usage
     * @param  array<string, string>  $rates
     */
    public function calculateActual(
        array $usage,
        array $rates = [],
        bool $failed = false,
        int $markupPercent = Money::DEFAULT_MARKUP_PERCENT,
    ): ActualCostBreakdown {
        $inputTextTokens = (int) ($usage['input_text_tokens'] ?? 0);
        $inputImageTokens = (int) ($usage['input_image_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $reasoningTokens = (int) ($usage['reasoning_tokens'] ?? 0);

        foreach ([
            'input_text_tokens' => $inputTextTokens,
            'input_image_tokens' => $inputImageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
        ] as $field => $count) {
            if ($count < 0) {
                throw new InvalidArgumentException("Token count for [{$field}] must be non-negative, got {$count}.");
            }
        }

        if ($failed) {
            return new ActualCostBreakdown(
                inputTextTokens: $inputTextTokens,
                inputImageTokens: $inputImageTokens,
                outputTokens: $outputTokens,
                reasoningTokens: $reasoningTokens,
                providerCostNano: 0,
                userCostNano: 0,
                profitNano: 0,
                rates: $rates,
            );
        }

        $resolvedRates = $this->requireRates($rates);

        $providerCostNano = $this->calculateProviderCostNano(
            $inputTextTokens,
            $inputImageTokens,
            $outputTokens,
            $reasoningTokens,
            $resolvedRates,
        );

        $userCostNano = Money::applyMarkup($providerCostNano, $markupPercent);
        $profitNano = Money::sub($userCostNano, $providerCostNano);

        return new ActualCostBreakdown(
            inputTextTokens: $inputTextTokens,
            inputImageTokens: $inputImageTokens,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
            providerCostNano: $providerCostNano,
            userCostNano: $userCostNano,
            profitNano: $profitNano,
            rates: $resolvedRates,
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function applyTextTokenVariance(int $estimatedTextTokens, float $variance): array
    {
        if ($estimatedTextTokens === 0 || $variance === 0.0) {
            return [$estimatedTextTokens, $estimatedTextTokens];
        }

        $varianceStr = number_format($variance, 6, '.', '');
        $minFactor = bcsub('1', $varianceStr, 6);
        $maxFactor = bcadd('1', $varianceStr, 6);

        $minProduct = bcmul((string) $estimatedTextTokens, $minFactor, 6);
        $maxProduct = bcmul((string) $estimatedTextTokens, $maxFactor, 6);

        $minTokens = (int) explode('.', $minProduct, 2)[0];
        [$maxWhole, $maxFrac] = array_pad(explode('.', $maxProduct, 2), 2, '0');
        $maxTokens = (int) $maxWhole + (ltrim($maxFrac, '0') !== '' ? 1 : 0);

        return [$minTokens, $maxTokens];
    }

    /**
     * @return array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}
     */
    private function requireRates(mixed $rates): array
    {
        if (! is_array($rates)) {
            throw new InvalidArgumentException('Pricing rates must be provided.');
        }

        $resolved = [];

        foreach (self::RATE_KEYS as $key) {
            if (! array_key_exists($key, $rates) || trim((string) $rates[$key]) === '') {
                throw new InvalidArgumentException("Missing required pricing rate [{$key}].");
            }

            $resolved[$key] = (string) $rates[$key];
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function findQualityConfig(mixed $qualities, ImageQuality $quality): array
    {
        if (! is_array($qualities)) {
            throw new InvalidArgumentException('Pricing configuration is missing qualities.');
        }

        foreach ($qualities as $item) {
            if (is_array($item) && ($item['value'] ?? null) === $quality->value) {
                return $item;
            }
        }

        throw new InvalidArgumentException("Pricing configuration is missing quality [{$quality->value}].");
    }

    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    private function calculateProviderCostNano(
        int $inputTextTokens,
        int $inputImageTokens,
        int $outputTokens,
        int $reasoningTokens,
        array $rates,
    ): int|string {
        $tokenGroups = [
            'input_text_tokens' => $inputTextTokens,
            'input_image_tokens' => $inputImageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
        ];

        $totalNano = '0';

        foreach ($tokenGroups as $key => $tokens) {
            if ($tokens === 0) {
                continue;
            }

            $nanoPerToken = Money::ratePerMillionToNanoPerToken($rates[$key]);
            $groupCost = bcmul((string) $tokens, $nanoPerToken, 6);
            $totalNano = bcadd($totalNano, $groupCost, 6);
        }

        return Money::toIntOrString(bcround($totalNano, 0, RoundingMode::HalfAwayFromZero));
    }
}
