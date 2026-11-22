<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsageMetricsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Format shared local financial totals (credit spend, provider cost, profit) in nanodollars and USD.
     *
     * @return array<string, int|string>
     */
    public static function formatFinancialMetrics(int $creditSpendNano, int $providerCostNano): array
    {
        $profitNano = $creditSpendNano - $providerCostNano;

        return [
            'credit_spend_nano' => $creditSpendNano,
            'credit_spend_usd' => Money::nanoToUsd($creditSpendNano),
            'credit_spend_formatted' => Money::formatDisplay($creditSpendNano),
            'provider_cost_nano' => $providerCostNano,
            'provider_cost_usd' => Money::nanoToUsd($providerCostNano),
            'provider_cost_formatted' => Money::formatDisplay($providerCostNano),
            'profit_nano' => $profitNano,
            'profit_usd' => Money::nanoToUsd($profitNano),
            'profit_formatted' => Money::formatDisplay($profitNano),
        ];
    }

    /**
     * Transform combined local + Pixelforge /v1/usage summary metrics into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metrics = is_array($this->resource) ? $this->resource : [];

        $creditSpendNano = (int) ($metrics['credit_spend_nano'] ?? 0);
        $providerCostNano = (int) ($metrics['provider_cost_nano'] ?? 0);
        $remoteCostNano = (int) ($metrics['remote_cost_nano'] ?? 0);
        $deltaNano = $providerCostNano - $remoteCostNano;

        $inputTextTokens = (int) ($metrics['input_text_tokens'] ?? 0);
        $inputImageTokens = (int) ($metrics['input_image_tokens'] ?? 0);
        $outputTokens = (int) ($metrics['output_tokens'] ?? 0);
        $reasoningTokens = (int) ($metrics['reasoning_tokens'] ?? 0);

        return [
            'succeeded_count' => (int) ($metrics['succeeded_count'] ?? 0),
            'failed_count' => (int) ($metrics['failed_count'] ?? 0),
            ...(array_key_exists('active_count', $metrics) ? ['active_count' => (int) $metrics['active_count']] : []),
            ...self::formatFinancialMetrics($creditSpendNano, $providerCostNano),
            'remote_generations' => (int) ($metrics['remote_generations'] ?? 0),
            'input_text_tokens' => $inputTextTokens,
            'input_image_tokens' => $inputImageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
            'total_tokens' => $inputTextTokens + $inputImageTokens + $outputTokens + $reasoningTokens,
            'remote_cost_nano' => $remoteCostNano,
            'remote_cost_usd' => Money::nanoToUsd($remoteCostNano),
            'remote_cost_formatted' => Money::formatDisplay($remoteCostNano),
            'delta_nano' => $deltaNano,
            'delta_usd' => Money::nanoToUsd($deltaNano),
            'delta_formatted' => Money::formatDisplay($deltaNano),
            'matches_remote' => $metrics['matches_remote'] ?? null,
        ];
    }
}
