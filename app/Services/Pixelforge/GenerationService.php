<?php

namespace App\Services\Pixelforge;

use App\Actions\Generations\CreateGeneration;
use App\Actions\Generations\FailGeneration;
use App\Actions\Generations\LockGeneration;
use App\Actions\Wallet\SettleWalletCharge;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Events\GenerationUpdated;
use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;

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
     * Create a generation, hold max estimated credits, and submit it to Pixelforge.
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

        $payload = [
            'prompt' => $prompt,
            'size' => $size->value,
            'quality' => $quality->value,
            'reference_images' => $references,
            'metadata' => [
                'local_generation_id' => (string) $generation->id,
                'user_id' => (string) $user->id,
            ],
        ];

        try {
            $response = $this->client->sendGeneration($payload, $generation->idempotency_key);

            return $this->updateFromProvider($generation, $response);
        } catch (PixelforgeApiException|ConnectionException $exception) {
            [$errorCode, $errorMessage] = $exception instanceof PixelforgeApiException
                ? [$exception->errorCode, $exception->errorMessage]
                : ['connection_error', $exception->getMessage()];

            return $this->markAsFailed($generation, $errorCode, $errorMessage);
        }
    }

    /**
     * Update a generation from a Pixelforge API response or webhook payload and settle/release billing.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateFromProvider(Generation $generation, array $data): Generation
    {
        [$updated, $shouldBroadcast] = $this->lockGeneration->handle($generation, function (Generation $locked) use ($data): array {
            $previousStatus = $locked->status;
            $previousErrorCode = $locked->error_code;

            if ($locked->pixelforge_id === null && filled($data['id'] ?? null)) {
                $locked->pixelforge_id = (string) $data['id'];
            }

            $incomingStatus = GenerationStatus::tryFrom((string) ($data['status'] ?? ''));

            if ($incomingStatus === null || $locked->status->isTerminal()) {
                if ($locked->isDirty()) {
                    $locked->save();
                }

                return [$locked->refresh(), false];
            }

            $this->updateStatus($locked, $incomingStatus, $data);
            $locked->refresh();

            $changed = $locked->status !== $previousStatus
                || ($previousErrorCode !== null && $locked->error_code === null);

            return [$locked, $changed];
        });

        if ($shouldBroadcast) {
            GenerationUpdated::dispatchSafely($updated);
        }

        return $updated;
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
        if (is_array($data['rates'] ?? null) && $data['rates'] !== [] && $status !== GenerationStatus::Failed) {
            $locked->locked_rates = $data['rates'];
        }

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
            $errorCode = (string) ($error['code'] ?? $data['error_code'] ?? $locked->error_code ?? 'generation_failed');
            $errorMessage = isset($error['message']) || isset($data['error_message'])
                ? (string) ($error['message'] ?? $data['error_message'])
                : $locked->error_message;

            $this->failGeneration->handle($locked, $errorCode, $errorMessage, $completedAt, $usage);

            return;
        }

        // Succeeded
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
            : $this->pricingService->currentRates();

        $breakdown = $this->pricingCalculator->calculateActual(
            usage: $locked->usage ?? [],
            rates: $lockedRates,
        );

        $this->settleWalletCharge->handle($locked, $breakdown->providerCostNano, $breakdown->userCostNano);
    }
}
