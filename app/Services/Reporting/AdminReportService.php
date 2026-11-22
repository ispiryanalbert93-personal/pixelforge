<?php

namespace App\Services\Reporting;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeUsageService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class AdminReportService
{
    public function __construct(
        private readonly PixelforgeUsageService $usageService,
    ) {}

    /**
     * Combine local billing/generation metrics with Pixelforge /v1/usage reconciliation.
     *
     * @return array<string, mixed>
     */
    public function build(?string $startDate = null, ?string $endDate = null): array
    {
        $remote = $this->usageService->getUsageReport($startDate, $endDate);
        $effectiveStart = (string) $remote['filters']['effective_start_date'];
        $effectiveEnd = (string) $remote['filters']['effective_end_date'];

        $completedGenerations = $this->fetchCompletedGenerations($effectiveStart, $effectiveEnd);
        $activeCountsByUser = $this->fetchActiveCountsByUser();
        $users = $this->fetchReportUsers();

        $local = $this->aggregateLocalMetrics($completedGenerations, $users, $activeCountsByUser, $effectiveStart, $effectiveEnd);

        $remoteAvailable = (bool) $remote['available'];
        $remoteDaysByDate = collect($remote['days'])->keyBy('date');

        $days = $this->mergeDailyRows(
            $local['daily'],
            $remoteDaysByDate,
            $remoteAvailable,
            $effectiveStart,
            $effectiveEnd,
        );
        $total = $this->mergeRemoteMetrics($local['totals'], $remote['total'], $remoteAvailable);

        return [
            'available' => $remoteAvailable,
            'error' => $remote['error'],
            'filters' => $remote['filters'],
            'total' => $total,
            'days' => $days,
            'users' => $local['users'],
            'daily_by_user' => $local['daily_by_user'],
        ];
    }

    /**
     * @return LazyCollection<int, Generation>
     */
    private function fetchCompletedGenerations(string $effectiveStart, string $effectiveEnd): LazyCollection
    {
        $appTimezone = config('app.timezone');
        $startAt = Carbon::parse($effectiveStart, 'UTC')->startOfDay()->timezone($appTimezone);
        $endAt = Carbon::parse($effectiveEnd, 'UTC')->endOfDay()->timezone($appTimezone);

        return Generation::query()
            ->select([
                'id',
                'user_id',
                'status',
                'actual_provider_cost_nano',
                'actual_user_cost_nano',
                'completed_at',
            ])
            ->whereIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->whereBetween('completed_at', [$startAt, $endAt])
            ->cursor();
    }

    /**
     * @return array<int, int>
     */
    private function fetchActiveCountsByUser(): array
    {
        return Generation::query()
            ->selectRaw('user_id, COUNT(*) as aggregate_count')
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->groupBy('user_id')
            ->pluck('aggregate_count', 'user_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function fetchReportUsers(): EloquentCollection
    {
        return User::query()
            ->with('wallet')
            ->where(function ($query): void {
                $query->where('is_admin', false)
                    ->orWhereHas('generations');
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  iterable<int, Generation>  $generations
     * @param  EloquentCollection<int, User>  $users
     * @param  array<int, int>  $activeCountsByUser
     * @return array{
     *     totals: array<string, int>,
     *     daily: array<string, array<string, int>>,
     *     users: array<int, array<string, mixed>>,
     *     daily_by_user: array<int, array<string, mixed>>
     * }
     */
    private function aggregateLocalMetrics(
        iterable $generations,
        EloquentCollection $users,
        array $activeCountsByUser,
        string $effectiveStart,
        string $effectiveEnd,
    ): array {
        $totals = [
            'succeeded_count' => 0,
            'failed_count' => 0,
            'active_count' => array_sum($activeCountsByUser),
            'credit_spend_nano' => 0,
            'provider_cost_nano' => 0,
        ];

        $dailyBuckets = [];
        $dailyByUserBuckets = [];
        $userStats = [];

        foreach ($users as $user) {
            $userStats[$user->id] = [
                'user' => $user,
                'succeeded_count' => 0,
                'failed_count' => 0,
                'active_count' => $activeCountsByUser[$user->id] ?? 0,
                'credit_spend_nano' => 0,
                'provider_cost_nano' => 0,
            ];
        }

        foreach ($generations as $generation) {
            $utcDate = $generation->completed_at?->copy()->utc()->toDateString();

            if ($utcDate === null || $utcDate < $effectiveStart || $utcDate > $effectiveEnd) {
                continue;
            }

            $isSucceeded = $generation->status === GenerationStatus::Succeeded;
            $creditNano = $isSucceeded ? (int) ($generation->actual_user_cost_nano ?? 0) : 0;
            $providerNano = $isSucceeded ? (int) ($generation->actual_provider_cost_nano ?? 0) : 0;
            $succeededDelta = $isSucceeded ? 1 : 0;
            $failedDelta = $isSucceeded ? 0 : 1;

            $totals['succeeded_count'] += $succeededDelta;
            $totals['failed_count'] += $failedDelta;
            $totals['credit_spend_nano'] += $creditNano;
            $totals['provider_cost_nano'] += $providerNano;

            $dailyBuckets[$utcDate] ??= [
                'succeeded_count' => 0,
                'failed_count' => 0,
                'credit_spend_nano' => 0,
                'provider_cost_nano' => 0,
            ];
            $dailyBuckets[$utcDate]['succeeded_count'] += $succeededDelta;
            $dailyBuckets[$utcDate]['failed_count'] += $failedDelta;
            $dailyBuckets[$utcDate]['credit_spend_nano'] += $creditNano;
            $dailyBuckets[$utcDate]['provider_cost_nano'] += $providerNano;

            if (! isset($userStats[$generation->user_id])) {
                continue;
            }

            $userStats[$generation->user_id]['succeeded_count'] += $succeededDelta;
            $userStats[$generation->user_id]['failed_count'] += $failedDelta;
            $userStats[$generation->user_id]['credit_spend_nano'] += $creditNano;
            $userStats[$generation->user_id]['provider_cost_nano'] += $providerNano;

            $dailyUserKey = "{$utcDate}:{$generation->user_id}";
            $dailyByUserBuckets[$dailyUserKey] ??= [
                'date' => $utcDate,
                'user' => $userStats[$generation->user_id]['user'],
                'succeeded_count' => 0,
                'failed_count' => 0,
                'credit_spend_nano' => 0,
                'provider_cost_nano' => 0,
            ];
            $dailyByUserBuckets[$dailyUserKey]['succeeded_count'] += $succeededDelta;
            $dailyByUserBuckets[$dailyUserKey]['failed_count'] += $failedDelta;
            $dailyByUserBuckets[$dailyUserKey]['credit_spend_nano'] += $creditNano;
            $dailyByUserBuckets[$dailyUserKey]['provider_cost_nano'] += $providerNano;
        }

        return [
            'totals' => $totals,
            'daily' => $dailyBuckets,
            'users' => $this->sortAndFormatUserRows($userStats),
            'daily_by_user' => $this->sortAndFormatDailyByUserRows($dailyByUserBuckets),
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $localDaily
     * @param  Collection<string, array<string, mixed>>  $remoteDaysByDate
     * @return array<int, array<string, mixed>>
     */
    private function mergeDailyRows(
        array $localDaily,
        Collection $remoteDaysByDate,
        bool $remoteAvailable,
        string $effectiveStart,
        string $effectiveEnd,
    ): array {
        $windowDates = $this->buildUtcDateRange($effectiveStart, $effectiveEnd);

        $allDates = array_values(array_unique([
            ...$windowDates,
            ...array_keys($localDaily),
            ...$remoteDaysByDate->keys()->all(),
        ]));
        rsort($allDates, SORT_STRING);

        return array_map(function (string $date) use ($localDaily, $remoteDaysByDate, $remoteAvailable): array {
            $local = $localDaily[$date] ?? [
                'succeeded_count' => 0,
                'failed_count' => 0,
                'credit_spend_nano' => 0,
                'provider_cost_nano' => 0,
            ];

            return [
                'date' => $date,
                ...$this->mergeRemoteMetrics($local, $remoteDaysByDate->get($date, []), $remoteAvailable),
            ];
        }, $allDates);
    }

    /**
     * @param  array<string, int>  $localMetrics
     * @param  array<string, mixed>  $remoteMetrics
     * @return array<string, mixed>
     */
    private function mergeRemoteMetrics(array $localMetrics, array $remoteMetrics, bool $remoteAvailable): array
    {
        $remoteCostNano = $remoteAvailable ? Money::usdToNano((string) ($remoteMetrics['cost_usd'] ?? '0')) : 0;

        return [
            ...$localMetrics,
            'remote_generations' => (int) ($remoteMetrics['generations'] ?? 0),
            'input_text_tokens' => (int) ($remoteMetrics['input_text_tokens'] ?? 0),
            'input_image_tokens' => (int) ($remoteMetrics['input_image_tokens'] ?? 0),
            'output_tokens' => (int) ($remoteMetrics['output_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($remoteMetrics['reasoning_tokens'] ?? 0),
            'remote_cost_nano' => $remoteCostNano,
            'matches_remote' => $remoteAvailable ? $localMetrics['provider_cost_nano'] === $remoteCostNano : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $userStats
     * @return array<int, array<string, mixed>>
     */
    private function sortAndFormatUserRows(array $userStats): array
    {
        $rows = array_map(static function (array $stat): array {
            /** @var User $user */
            $user = $stat['user'];
            $wallet = $user->wallet;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'succeeded_count' => $stat['succeeded_count'],
                'failed_count' => $stat['failed_count'],
                'active_count' => $stat['active_count'],
                'credit_spend_nano' => $stat['credit_spend_nano'],
                'provider_cost_nano' => $stat['provider_cost_nano'],
                'balance_nanodollars' => (int) $wallet->balance_nanodollars,
                'balance_usd' => $wallet->balance_usd,
                'balance_formatted' => Money::formatDisplay((int) $wallet->balance_nanodollars),
                'reserved_nanodollars' => (int) $wallet->reserved_nanodollars,
                'reserved_usd' => $wallet->reserved_usd,
                'reserved_formatted' => Money::formatDisplay((int) $wallet->reserved_nanodollars),
            ];
        }, array_values($userStats));

        usort(
            $rows,
            static fn (array $a, array $b): int => [$b['credit_spend_nano'], $a['id']] <=> [$a['credit_spend_nano'], $b['id']],
        );

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $dailyByUserBuckets
     * @return array<int, array<string, mixed>>
     */
    private function sortAndFormatDailyByUserRows(array $dailyByUserBuckets): array
    {
        $rows = array_map(static function (array $bucket): array {
            /** @var User $user */
            $user = $bucket['user'];

            return [
                'date' => $bucket['date'],
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'succeeded_count' => $bucket['succeeded_count'],
                'failed_count' => $bucket['failed_count'],
                'credit_spend_nano' => $bucket['credit_spend_nano'],
                'provider_cost_nano' => $bucket['provider_cost_nano'],
            ];
        }, array_values($dailyByUserBuckets));

        usort(
            $rows,
            static fn (array $a, array $b): int => [$b['date'], $b['credit_spend_nano'], $a['user_id']]
                <=> [$a['date'], $a['credit_spend_nano'], $b['user_id']],
        );

        return $rows;
    }

    /**
     * Build an inclusive list of UTC dates (YYYY-MM-DD) from start to end.
     *
     * @return list<string>
     */
    private function buildUtcDateRange(string $startDate, string $endDate): array
    {
        $cursor = Carbon::parse($startDate, 'UTC')->startOfDay();
        $end = Carbon::parse($endDate, 'UTC')->startOfDay();

        $dates = [];

        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }
}
