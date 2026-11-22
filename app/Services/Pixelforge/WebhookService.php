<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WebhookService
{
    public function __construct(
        private readonly GenerationService $generationService,
    ) {}

    /**
     * Process a verified Pixelforge webhook delivery idempotently.
     *
     * @param  array<string, mixed>  $parsedInput
     * @return array{received: true, duplicate?: true}
     */
    public function handle(
        string $rawBody,
        array $parsedInput = [],
        mixed $eventIdHeader = null,
        mixed $eventTypeHeader = null,
    ): array {
        $payload = $this->decodePayload($rawBody, $parsedInput);
        $eventId = $this->resolveStringField($eventIdHeader, $payload['id'] ?? null, hash('sha256', $rawBody));
        $eventType = $this->resolveStringField($eventTypeHeader, $payload['type'] ?? null, 'unknown');

        return Cache::lock("pixelforge-webhook-event:{$eventId}", 10)
            ->block(5, function () use ($payload, $eventId, $eventType): array {
                $existing = WebhookEvent::query()->where('event_id', $eventId)->first();

                if ($existing !== null && $existing->processed_at !== null) {
                    return [
                        'received' => true,
                        'duplicate' => true,
                    ];
                }

                $generationData = $this->extractGenerationData($payload);
                $pixelforgeGenerationId = null;

                if (str_starts_with($eventType, 'generation.') && $generationData !== []) {
                    $pixelforgeGenerationId = $this->resolvePixelforgeGenerationId($generationData);

                    if (empty($generationData['status'])) {
                        $generationData['status'] = Str::after($eventType, 'generation.');
                    }

                    $generation = $this->resolveGeneration($pixelforgeGenerationId, $generationData);

                    if ($generation !== null) {
                        $this->generationService->updateFromProvider($generation, $generationData);
                    }
                }

                $event = $existing ?? new WebhookEvent(['event_id' => $eventId]);
                $event->event_type = $eventType;
                $event->pixelforge_generation_id = $pixelforgeGenerationId;
                $event->payload = $payload;
                $event->processed_at = now();

                try {
                    $event->save();
                } catch (UniqueConstraintViolationException) {
                    return [
                        'received' => true,
                        'duplicate' => true,
                    ];
                }

                return ['received' => true];
            });
    }

    /**
     * @param  array<string, mixed>  $parsedInput
     * @return array<string, mixed>
     */
    private function decodePayload(string $rawBody, array $parsedInput): array
    {
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $parsedInput;
    }

    private function resolveStringField(mixed $headerValue, mixed $payloadValue, string $fallback): string
    {
        if (is_string($headerValue) && $headerValue !== '') {
            return $headerValue;
        }

        if (is_string($payloadValue) && $payloadValue !== '') {
            return $payloadValue;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractGenerationData(array $payload): array
    {
        $dataObject = data_get($payload, 'data.object');

        if (is_array($dataObject)) {
            return $dataObject;
        }

        $data = $payload['data'] ?? null;

        if (is_array($data)) {
            return $data;
        }

        $isEventEnvelope = ($payload['object'] ?? null) === 'event'
            || (is_string($payload['id'] ?? null) && str_starts_with($payload['id'], 'evt_'));

        return $isEventEnvelope ? [] : $payload;
    }

    /**
     * @param  array<string, mixed>  $generationData
     */
    private function resolvePixelforgeGenerationId(array $generationData): ?string
    {
        $id = $generationData['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $generationData
     */
    private function resolveGeneration(?string $pixelforgeGenerationId, array $generationData): ?Generation
    {
        if ($pixelforgeGenerationId !== null) {
            $generation = Generation::query()
                ->where('pixelforge_id', $pixelforgeGenerationId)
                ->first();

            if ($generation !== null) {
                return $generation;
            }
        }

        $localGenerationId = data_get($generationData, 'metadata.local_generation_id');

        if (is_int($localGenerationId) || (is_string($localGenerationId) && ctype_digit($localGenerationId))) {
            return Generation::query()->find((int) $localGenerationId);
        }

        return null;
    }
}
