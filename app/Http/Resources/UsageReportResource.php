<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsageReportResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the combined admin report into an Inertia/JSON-ready payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = is_array($this->resource) ? $this->resource : [];

        return [
            'available' => (bool) ($report['available'] ?? false),
            'error' => $report['error'] ?? null,
            'days' => UsageDayResource::collection($report['days'] ?? [])->resolve($request),
            'total' => UsageMetricsResource::make($report['total'] ?? [])->resolve($request),
            'users' => $this->appendFinancialMetrics($report['users'] ?? null),
            'daily_by_user' => $this->appendFinancialMetrics($report['daily_by_user'] ?? null),
            'filters' => $report['filters'] ?? [
                'start_date' => null,
                'end_date' => null,
                'effective_start_date' => null,
                'effective_end_date' => null,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function appendFinancialMetrics(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            ...$row,
            ...UsageMetricsResource::formatFinancialMetrics(
                $row['credit_spend_nano'] ?? 0,
                $row['provider_cost_nano'] ?? 0,
            ),
        ], $rows);
    }
}
