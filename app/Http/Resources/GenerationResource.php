<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * Transform the generation into its canonical studio and broadcast payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actualUserCostNano = $this->actual_user_cost_nano;

        return [
            'id' => (int) $this->id,
            'pixelforge_id' => $this->pixelforge_id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images ?? [],
            'usage' => $this->usage,
            'estimated_min_user_cost_nano' => (int) $this->estimated_min_user_cost_nano,
            'estimated_user_cost_nano' => (int) $this->estimated_user_cost_nano,
            'estimated_max_user_cost_nano' => (int) $this->estimated_max_user_cost_nano,
            'estimated_min_user_cost_usd' => Money::nanoToUsd((int) $this->estimated_min_user_cost_nano),
            'estimated_user_cost_usd' => $this->estimated_user_cost_usd,
            'estimated_max_user_cost_usd' => Money::nanoToUsd((int) $this->estimated_max_user_cost_nano),
            'estimated_user_cost_formatted' => Money::formatDisplay((int) $this->estimated_user_cost_nano),
            'reserved_user_cost_nano' => (int) $this->reserved_user_cost_nano,
            'reserved_user_cost_usd' => Money::nanoToUsd((int) $this->reserved_user_cost_nano),
            'actual_user_cost_nano' => $actualUserCostNano !== null ? (int) $actualUserCostNano : null,
            'actual_user_cost_usd' => $this->actual_user_cost_usd,
            'actual_user_cost_formatted' => $actualUserCostNano !== null ? Money::formatDisplay((int) $actualUserCostNano) : null,
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
