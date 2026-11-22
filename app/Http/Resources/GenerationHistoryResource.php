<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * @mixin Generation
 */
class GenerationHistoryResource extends JsonResource
{
    /**
     * Transform the resource into a slim payload for the generation history table.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = GenerationResource::make($this->resource)->toArray($request);

        return [
            ...Arr::only($base, [
                'id',
                'pixelforge_id',
                'status',
                'prompt',
                'size',
                'quality',
                'usage',
                'estimated_min_user_cost_usd',
                'estimated_user_cost_formatted',
                'estimated_max_user_cost_usd',
                'reserved_user_cost_usd',
                'actual_user_cost_usd',
                'actual_user_cost_formatted',
                'image_url',
                'error_message',
                'created_at',
            ]),
            'reference_images_count' => count($this->reference_images ?? []),
        ];
    }
}
