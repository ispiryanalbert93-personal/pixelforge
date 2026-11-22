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
        public int $minProviderCostNano,
        public int $expectedProviderCostNano,
        public int $maxProviderCostNano,
        public int $minUserCostNano,
        public int $expectedUserCostNano,
        public int $maxUserCostNano,
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
                'min' => $this->minProviderCostNano,
                'expected' => $this->expectedProviderCostNano,
                'max' => $this->maxProviderCostNano,
            ],
            'user_cost_nano' => [
                'min' => $this->minUserCostNano,
                'expected' => $this->expectedUserCostNano,
                'max' => $this->maxUserCostNano,
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
