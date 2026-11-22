<?php

namespace App\Services\Pixelforge;

use App\Exceptions\PixelforgeApiException;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UsageService
{
    public const int DEFAULT_RANGE_DAYS = 30;

    public const int CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * Fetch and normalize Pixelforge usage data from GET /v1/usage.
     *
     * @return array{
     *     available: bool,
     *     error: ?string,
     *     days: Collection<int, array<string, mixed>>,
     *     total: array<string, mixed>,
     *     filters: array{
     *         start_date: ?string,
     *         end_date: ?string,
     *         effective_start_date: string,
     *         effective_end_date: string
     *     }
     * }
     */
    public function getUsageReport(?string $startDate = null, ?string $endDate = null): array
    {
        [$queryStart, $queryEnd, $fallbackStart, $fallbackEnd] = $this->resolveWindow($startDate, $endDate);
        $cacheKey = sprintf('pixelforge:usage:%s:%s', $queryStart ?? 'default', $queryEnd ?? 'default');

        $payload = Cache::get($cacheKey);

        if (! is_array($payload)) {
            try {
                $payload = $this->client->getUsage($queryStart, $queryEnd);
                Cache::put($cacheKey, $payload, self::CACHE_TTL_SECONDS);
            } catch (PixelforgeApiException|ConnectionException $exception) {
                Log::warning('Failed to fetch Pixelforge /v1/usage report.', [
                    'start_date' => $queryStart,
                    'end_date' => $queryEnd,
                    'message' => $exception->getMessage(),
                ]);

                return [
                    'available' => false,
                    'error' => 'Unable to reach Pixelforge /v1/usage right now. Local billing metrics remain available below.',
                    'days' => collect(),
                    'total' => [],
                    'filters' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'effective_start_date' => $fallbackStart,
                        'effective_end_date' => $fallbackEnd,
                    ],
                ];
            }
        }

        $effectiveStartDate = $queryStart
            ?? (is_string($payload['start_date'] ?? null) ? $payload['start_date'] : $fallbackStart);
        $effectiveEndDate = $queryEnd
            ?? (is_string($payload['end_date'] ?? null) ? $payload['end_date'] : $fallbackEnd);

        $rawDays = $payload['data'] ?? $payload['days'] ?? [];

        $days = collect(is_array($rawDays) ? $rawDays : [])
            ->filter(static fn (mixed $entry): bool => is_array($entry) && ! empty($entry['date']))
            ->map(static fn (array $entry): array => [
                ...$entry,
                'date' => (string) $entry['date'],
            ])
            ->sortByDesc('date')
            ->values();

        $total = is_array($payload['total'] ?? null)
            ? $payload['total']
            : $this->sumDays($days);

        return [
            'available' => true,
            'error' => null,
            'days' => $days,
            'total' => $total,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'effective_start_date' => $effectiveStartDate,
                'effective_end_date' => $effectiveEndDate,
            ],
        ];
    }

    /**
     * Resolve the /v1/usage query bounds and fallback UTC display window.
     *
     * Default window is the last 30 UTC days inclusive, ending today.
     *
     * @return array{0: ?string, 1: ?string, 2: string, 3: string}
     */
    private function resolveWindow(?string $startDate, ?string $endDate): array
    {
        $todayUtc = Carbon::now('UTC')->startOfDay();
        $defaultOffset = self::DEFAULT_RANGE_DAYS - 1;

        if ($startDate === null && $endDate === null) {
            return [
                null,
                null,
                $todayUtc->copy()->subDays($defaultOffset)->toDateString(),
                $todayUtc->toDateString(),
            ];
        }

        $resolvedEnd = $endDate ?? $todayUtc->toDateString();
        $resolvedStart = $startDate ?? Carbon::parse($resolvedEnd, 'UTC')
            ->subDays($defaultOffset)
            ->toDateString();

        return [$resolvedStart, $resolvedEnd, $resolvedStart, $resolvedEnd];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private function sumDays(Collection $days): array
    {
        $costNano = '0';

        foreach ($days as $day) {
            $costNano = Money::add($costNano, Money::usdToNano((string) ($day['cost_usd'] ?? '0')));
        }

        return [
            'generations' => (int) $days->sum('generations'),
            'input_text_tokens' => (int) $days->sum('input_text_tokens'),
            'input_image_tokens' => (int) $days->sum('input_image_tokens'),
            'output_tokens' => (int) $days->sum('output_tokens'),
            'reasoning_tokens' => (int) $days->sum('reasoning_tokens'),
            'cost_usd' => Money::nanoToUsd($costNano),
        ];
    }
}
