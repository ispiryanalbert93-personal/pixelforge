<?php

namespace App\Services\Pixelforge\DTO;

use App\Support\Money;

final readonly class ActualCostBreakdown
{
    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $providerCostNano,
        public int $userCostNano,
        public int $profitNano,
        public array $rates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tokens' => [
                'input_text_tokens' => $this->inputTextTokens,
                'input_image_tokens' => $this->inputImageTokens,
                'output_tokens' => $this->outputTokens,
                'reasoning_tokens' => $this->reasoningTokens,
            ],
            'provider_cost_nano' => $this->providerCostNano,
            'user_cost_nano' => $this->userCostNano,
            'profit_nano' => $this->profitNano,
            'provider_cost_usd' => Money::nanoToUsd($this->providerCostNano),
            'user_cost_usd' => Money::nanoToUsd($this->userCostNano),
            'profit_usd' => Money::nanoToUsd($this->profitNano),
            'rates' => $this->rates,
        ];
    }
}
