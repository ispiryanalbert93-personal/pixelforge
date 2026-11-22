<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actualUserCostNano = $this->actual_user_cost_nano;
        $minNano = $this->estimated_min_user_cost_nano ?? 0;
        $estNano = $this->estimated_user_cost_nano ?? 0;
        $maxNano = $this->estimated_max_user_cost_nano ?? 0;
        $reservedNano = $this->reserved_user_cost_nano ?? 0;

        return [
            'id' => (int) $this->id,
            'pixelforge_id' => $this->pixelforge_id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images ?? [],
            'usage' => $this->usage,
            'estimated_min_user_cost_nano' => Money::toNumber($minNano),
            'estimated_user_cost_nano' => Money::toNumber($estNano),
            'estimated_max_user_cost_nano' => Money::toNumber($maxNano),
            'estimated_min_user_cost_usd' => Money::nanoToUsd($minNano),
            'estimated_user_cost_usd' => $this->estimated_user_cost_usd,
            'estimated_max_user_cost_usd' => Money::nanoToUsd($maxNano),
            'estimated_user_cost_formatted' => Money::formatDisplay($estNano),
            'reserved_user_cost_nano' => Money::toNumber($reservedNano),
            'reserved_user_cost_usd' => Money::nanoToUsd($reservedNano),
            'actual_user_cost_nano' => $actualUserCostNano !== null ? Money::toNumber($actualUserCostNano) : null,
            'actual_user_cost_usd' => $this->actual_user_cost_usd,
            'actual_user_cost_formatted' => $actualUserCostNano !== null ? Money::formatDisplay($actualUserCostNano) : null,
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
