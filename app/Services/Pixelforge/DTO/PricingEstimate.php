<?php

namespace App\Services\Pixelforge\DTO;

use App\Support\Money;

final readonly class PricingEstimate
{
    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    public function __construct(
        public int $estimatedTextTokens,
        public int $minTextTokens,
        public int $maxTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $minReasoningTokens,
        public int $midpointReasoningTokens,
        public int $maxReasoningTokens,
        public int|string $minProviderCostNano,
        public int|string $expectedProviderCostNano,
        public int|string $maxProviderCostNano,
        public int|string $minUserCostNano,
        public int|string $expectedUserCostNano,
        public int|string $maxUserCostNano,
        public array $rates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tokens' => [
                'input_text' => [
                    'min' => $this->minTextTokens,
                    'estimated' => $this->estimatedTextTokens,
                    'max' => $this->maxTextTokens,
                ],
                'input_image' => $this->inputImageTokens,
                'output' => $this->outputTokens,
                'reasoning' => [
                    'min' => $this->minReasoningTokens,
                    'midpoint' => $this->midpointReasoningTokens,
                    'max' => $this->maxReasoningTokens,
                ],
            ],
            'provider_cost_nano' => [
                'min' => Money::toNumber($this->minProviderCostNano),
                'expected' => Money::toNumber($this->expectedProviderCostNano),
                'max' => Money::toNumber($this->maxProviderCostNano),
            ],
            'user_cost_nano' => [
                'min' => Money::toNumber($this->minUserCostNano),
                'expected' => Money::toNumber($this->expectedUserCostNano),
                'max' => Money::toNumber($this->maxUserCostNano),
            ],
            'provider_cost_usd' => [
                'min' => Money::nanoToUsd($this->minProviderCostNano),
                'expected' => Money::nanoToUsd($this->expectedProviderCostNano),
                'max' => Money::nanoToUsd($this->maxProviderCostNano),
            ],
            'user_cost_usd' => [
                'min' => Money::nanoToUsd($this->minUserCostNano),
                'expected' => Money::nanoToUsd($this->expectedUserCostNano),
                'max' => Money::nanoToUsd($this->maxUserCostNano),
            ],
            'rates' => $this->rates,
        ];
    }
}
