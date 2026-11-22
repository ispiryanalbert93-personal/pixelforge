<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsageDayResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform a combined UTC day entry (local DB + Pixelforge /v1/usage).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $day = is_array($this->resource) ? $this->resource : [];

        return [
            'date' => (string) ($day['date'] ?? ''),
            ...(new UsageMetricsResource($day))->resolve($request),
        ];
    }
}
