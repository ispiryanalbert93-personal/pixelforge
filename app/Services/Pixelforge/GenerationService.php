<?php

namespace App\Services\Pixelforge;

use App\Actions\Generations\CreateGeneration;
use App\Actions\Generations\FailGeneration;
use App\Actions\Generations\LockGeneration;
use App\Actions\Wallet\SettleWalletCharge;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\WalletTransactionType;
use App\Events\GenerationUpdated;
use App\Exceptions\PixelforgeApiException;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GenerationService
{
    public function __construct(
        private readonly Client $client,
        private readonly PricingCalculator $pricingCalculator,
        private readonly PricingService $pricingService,
        private readonly CreateGeneration $createGeneration,
        private readonly LockGeneration $lockGeneration,
        private readonly FailGeneration $failGeneration,
        private readonly SettleWalletCharge $settleWalletCharge,
    ) {}

    /**
     * Create a generation, hold max estimated credits, and dispatch submission to Pixelforge.
     *
     * @param  array<int, string>  $referenceImages
     */
    public function initiate(
        User $user,
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        array $referenceImages = [],
    ): Generation {
        $references = array_values(array_filter(
            $referenceImages,
            static fn (mixed $url): bool => is_string($url) && $url !== '',
        ));

        $pricingConfig = $this->pricingService->getPricingConfig();

        $estimate = $this->pricingCalculator->estimate(
            prompt: $prompt,
            size: $size,
            quality: $quality,
            referenceImageCount: count($references),
            pricingConfig: $pricingConfig,
        );

        $generation = $this->createGeneration->handle(
            $user,
            $prompt,
            $size,
            $quality,
            $references,
            $estimate,
        );

        SubmitGenerationToPixelforge::dispatch($generation->id);

        return $generation->fresh() ?? $generation;
    }

    /**
     * Submit an existing queued generation to Pixelforge with Idempotency-Key.
     *
     * Returns a retry delay (in seconds) when the queued job should release and retry, or null when finished.
     */
    public function submitGeneration(
        int $generationId,
        bool $isAsyncQueue = false,
        int $attempt = 1,
        int $maxTries = 1,
    ): ?int {
        $generation = Generation::query()->find($generationId);

        if ($generation === null || $generation->status->isTerminal() || $generation->pixelforge_id !== null) {
            return null;
        }

        $payload = $this->buildSubmissionPayload($generation);

        try {
            $response = $this->client->sendGeneration($payload, $generation->idempotency_key);
        } catch (PixelforgeApiException|ConnectionException $exception) {
            return $this->handleSubmissionException($generation, $exception, $isAsyncQueue, $attempt, $maxTries);
        }

        $recalculatedMaxCostNano = $this->handleRateDrift($generation, $response);

        $this->updateFromProvider($generation, $response, $recalculatedMaxCostNano);

        return null;
    }

    /**
     * Synchronize a generation directly from Pixelforge using GET /v1/generations/{id}
     * (or Idempotency-Key replay if submission never recorded a provider ID).
     */
    public function syncFromProvider(Generation $generation): Generation
    {
        if ($generation->status->isTerminal() && ! $generation->isAmbiguousLocalFailure()) {
            return $generation;
        }

        if ($generation->pixelforge_id !== null) {
            $response = $this->client->getGeneration($generation->pixelforge_id);

            return $this->updateFromProvider($generation, $response);
        }

        $response = $this->client->sendGeneration(
            $this->buildSubmissionPayload($generation),
            $generation->idempotency_key,
        );

        $recalculatedMaxCostNano = $this->handleRateDrift($generation, $response);

        return $this->updateFromProvider($generation, $response, $recalculatedMaxCostNano);
    }

    /**
     * Update a generation from a Pixelforge API response or webhook payload and settle/release billing.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateFromProvider(
        Generation $generation,
        array $data,
        ?int $recalculatedMaxCostNano = null,
    ): Generation {
        [$updated, $shouldBroadcast] = $this->lockGeneration->handle(
            $generation,
            function (Generation $locked, Wallet $lockedWallet) use ($data, $recalculatedMaxCostNano): array {
                $previousStatus = $locked->status;
                $previousErrorCode = $locked->error_code;
                $previousErrorMessage = $locked->error_message;
                $wasAmbiguousLocalFailure = $locked->isAmbiguousLocalFailure();

                if ($locked->pixelforge_id === null && filled($data['id'] ?? null)) {
                    $locked->pixelforge_id = (string) $data['id'];
                }

                $incomingStatus = GenerationStatus::tryFrom((string) ($data['status'] ?? ''));

                if ($incomingStatus === null) {
                    if ($locked->isDirty()) {
                        $locked->save();
                    }

                    return [$locked->refresh(), false];
                }

                // Recover from an ambiguous local submit failure (e.g. POST timeout) if Pixelforge later reports the generation.
                if ($locked->status === GenerationStatus::Failed) {
                    if (! $wasAmbiguousLocalFailure) {
                        if ($locked->isDirty()) {
                            $locked->save();
                        }

                        return [$locked->refresh(), false];
                    }

                    $this->recoverFromAmbiguousFailure($locked, $incomingStatus, $data);
                    $locked->refresh();

                    return [$locked, true];
                }

                if ($locked->status->isTerminal()) {
                    if ($locked->isDirty()) {
                        $locked->save();
                    }

                    return [$locked->refresh(), false];
                }

                $this->applyRateAdjustment($locked, $lockedWallet, $data, $recalculatedMaxCostNano);
                $this->updateStatus($locked, $incomingStatus, $data);
                $locked->refresh();

                $changed = $locked->status !== $previousStatus
                    || ($previousErrorCode !== null && $locked->error_code === null)
                    || ($previousErrorMessage !== null && $locked->error_message === null);

                return [$locked, $changed];
            },
        );

        if ($shouldBroadcast) {
            GenerationUpdated::dispatchSafely($updated);
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSubmissionPayload(Generation $generation): array
    {
        return [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images ?? [],
            'metadata' => [
                'local_generation_id' => (string) $generation->id,
                'user_id' => (string) $generation->user_id,
            ],
        ];
    }

    private function handleSubmissionException(
        Generation $generation,
        PixelforgeApiException|ConnectionException $exception,
        bool $isAsyncQueue,
        int $attempt,
        int $maxTries,
    ): ?int {
        $isRetryable = $exception instanceof ConnectionException
            || $exception->isRetryableRateOrConcurrencyLimit()
            || $exception->isOverloaded()
            || $exception->isTransientServerError();

        if ($isAsyncQueue && $isRetryable && $attempt < $maxTries) {
            $delay = $this->resolveRetryDelaySeconds($exception, $attempt);
            $this->markRetryWaiting($generation, $delay);

            return $delay;
        }

        $errorCode = $exception instanceof PixelforgeApiException
            ? $exception->errorCode
            : 'connection_error';

        $providerMessage = $exception instanceof PixelforgeApiException
            ? $exception->errorMessage
            : null;

        Log::warning('Pixelforge generation submission failed.', [
            'generation_id' => $generation->id,
            'error_code' => $errorCode,
            'message' => $exception->getMessage(),
            'attempt' => $attempt,
        ]);

        $this->markAsFailed(
            $generation,
            $errorCode,
            ErrorFormatter::forGeneration($errorCode, $providerMessage),
        );

        return null;
    }

    private function resolveRetryDelaySeconds(PixelforgeApiException|ConnectionException $exception, int $attempt): int
    {
        if ($exception instanceof PixelforgeApiException && $exception->retryAfterSeconds !== null) {
            return min(60, max(1, $exception->retryAfterSeconds));
        }

        return min(60, (2 ** max(1, $attempt)) * 2);
    }

    private function markRetryWaiting(Generation $generation, int $delaySeconds): void
    {
        [$updated, $shouldBroadcast] = $this->lockGeneration->handle(
            $generation,
            function (Generation $locked) use ($delaySeconds): array {
                if ($locked->status->isTerminal() || $locked->pixelforge_id !== null) {
                    return [$locked, false];
                }

                $locked->forceFill([
                    'error_message' => "Waiting for Pixelforge capacity (retrying in {$delaySeconds}s)...",
                ])->save();

                return [$locked->refresh(), true];
            },
        );

        if ($shouldBroadcast) {
            GenerationUpdated::dispatchSafely($updated);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function handleRateDrift(Generation $generation, array $response): ?int
    {
        $responseRates = $response['rates'] ?? null;

        if (! is_array($responseRates) || $responseRates === [] || $responseRates === $generation->locked_rates) {
            return null;
        }

        Log::warning('Pixelforge rates changed while pricing cache was warm; invalidating pricing cache.', [
            'generation_id' => $generation->id,
            'cached_rates' => $generation->locked_rates,
            'provider_rates' => $responseRates,
        ]);

        $this->pricingService->forgetCache();

        try {
            $freshConfig = $this->pricingService->getPricingConfig();
            $updatedConfig = [...$freshConfig, 'rates' => $responseRates];

            $estimate = $this->pricingCalculator->estimate(
                prompt: $generation->prompt,
                size: $generation->size,
                quality: $generation->quality,
                referenceImageCount: count($generation->reference_images ?? []),
                pricingConfig: $updatedConfig,
            );

            return $estimate->maxUserCostNano;
        } catch (PixelforgeApiException|ConnectionException|\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyRateAdjustment(
        Generation $locked,
        Wallet $lockedWallet,
        array $data,
        ?int $recalculatedMaxCostNano,
    ): void {
        if (is_array($data['rates'] ?? null) && $data['rates'] !== []) {
            $locked->locked_rates = $data['rates'];
        }

        if ($recalculatedMaxCostNano === null || $locked->settled_at !== null) {
            return;
        }

        $currentReserved = (int) $locked->reserved_user_cost_nano;
        if ($recalculatedMaxCostNano <= $currentReserved) {
            return;
        }

        $delta = $recalculatedMaxCostNano - $currentReserved;
        $locked->estimated_max_user_cost_nano = $recalculatedMaxCostNano;

        if ((int) $lockedWallet->balance_nanodollars < $delta) {
            Log::warning('Insufficient wallet balance to cover mid-flight Pixelforge rate increase hold delta.', [
                'generation_id' => $locked->id,
                'delta_nano' => $delta,
                'available_nano' => $lockedWallet->balance_nanodollars,
            ]);

            return;
        }

        $lockedWallet->balance_nanodollars -= $delta;
        $lockedWallet->reserved_nanodollars += $delta;
        $lockedWallet->save();

        $locked->reserved_user_cost_nano = $recalculatedMaxCostNano;

        WalletTransaction::create([
            'user_id' => $lockedWallet->user_id,
            'wallet_id' => $lockedWallet->id,
            'generation_id' => $locked->id,
            'type' => WalletTransactionType::Hold,
            'amount_nanodollars' => -$delta,
            'balance_after_nanodollars' => $lockedWallet->balance_nanodollars,
            'reserved_after_nanodollars' => $lockedWallet->reserved_nanodollars,
            'description' => "Rate adjustment hold for generation {$locked->id}",
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recoverFromAmbiguousFailure(Generation $locked, GenerationStatus $incomingStatus, array $data): void
    {
        Log::info('Recovering generation from ambiguous local submit failure via upstream provider payload.', [
            'generation_id' => $locked->id,
            'pixelforge_id' => $locked->pixelforge_id,
            'incoming_status' => $incomingStatus->value,
        ]);

        if ($incomingStatus === GenerationStatus::Failed) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];
            $errorCode = (string) ($error['code'] ?? 'generation_failed');
            $providerMessage = is_string($error['message'] ?? null) ? $error['message'] : null;

            $locked->forceFill([
                'error_code' => $errorCode,
                'error_message' => ErrorFormatter::forGeneration($errorCode, $providerMessage),
                'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : $locked->usage,
                'completed_at' => filled($data['completed_at'] ?? null)
                    ? Carbon::parse((string) $data['completed_at'])
                    : ($locked->completed_at ?? now()),
            ])->save();

            return;
        }

        $locked->forceFill([
            'status' => GenerationStatus::Queued,
            'error_code' => null,
            'error_message' => null,
            'settled_at' => null,
            'actual_provider_cost_nano' => null,
            'actual_user_cost_nano' => null,
            'completed_at' => null,
        ])->save();

        if (is_array($data['rates'] ?? null) && $data['rates'] !== []) {
            $locked->locked_rates = $data['rates'];
        }

        $this->updateStatus($locked, $incomingStatus, $data);
    }

    private function markAsFailed(Generation $generation, string $errorCode, string $errorMessage): Generation
    {
        [$updated, $shouldBroadcast] = $this->lockGeneration->handle(
            $generation,
            function (Generation $locked) use ($errorCode, $errorMessage): array {
                if ($locked->status->isTerminal() || $locked->pixelforge_id !== null) {
                    return [$locked, false];
                }

                $this->failGeneration->handle($locked, $errorCode, $errorMessage);

                return [$locked->refresh(), true];
            },
        );

        if ($shouldBroadcast) {
            GenerationUpdated::dispatchSafely($updated);
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateStatus(Generation $locked, GenerationStatus $status, array $data): void
    {
        if (! $status->isTerminal()) {
            if ($locked->status === $status || $locked->canTransitionTo($status)) {
                $locked->forceFill([
                    'status' => $status,
                    'error_code' => null,
                    'error_message' => null,
                ]);
            }

            if ($locked->isDirty()) {
                $locked->save();
            }

            return;
        }

        $completedAt = filled($data['completed_at'] ?? null)
            ? Carbon::parse((string) $data['completed_at'])
            : ($locked->completed_at ?? now());

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : null;

        if ($status === GenerationStatus::Failed) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];
            $errorCode = (string) ($error['code'] ?? $locked->error_code ?? 'generation_failed');
            $providerMessage = is_string($error['message'] ?? null) ? $error['message'] : null;
            $errorMessage = ErrorFormatter::forGeneration($errorCode, $providerMessage ?? $locked->error_message);

            $this->failGeneration->handle($locked, $errorCode, $errorMessage, $completedAt, $usage);

            return;
        }

        $locked->forceFill([
            'status' => GenerationStatus::Succeeded,
            'error_code' => null,
            'error_message' => null,
            'image_url' => filled($data['image_url'] ?? null) ? (string) $data['image_url'] : $locked->image_url,
            'image_url_expires_at' => filled($data['image_url_expires_at'] ?? null)
                ? Carbon::parse((string) $data['image_url_expires_at'])
                : $locked->image_url_expires_at,
            'usage' => $usage ?? $locked->usage,
            'completed_at' => $completedAt,
        ])->save();

        $lockedRates = is_array($locked->locked_rates) && $locked->locked_rates !== []
            ? $locked->locked_rates
            : (is_array($data['rates'] ?? null) ? $data['rates'] : []);

        if ($lockedRates === []) {
            throw new RuntimeException("Cannot settle generation {$locked->id}: missing locked pricing rates.");
        }

        $breakdown = $this->pricingCalculator->calculateActual(
            usage: $locked->usage ?? [],
            rates: $lockedRates,
        );

        $this->settleWalletCharge->handle($locked, $breakdown->providerCostNano, $breakdown->userCostNano);
    }
}
