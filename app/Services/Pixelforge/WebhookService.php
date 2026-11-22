<?php

namespace App\Services\Pixelforge;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WebhookService
{
    public function __construct(
        private readonly GenerationService $generationService,
    ) {}

    /**
     * Persist and deduplicate a verified Pixelforge webhook delivery, then dispatch async processing.
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
        $eventId = $this->resolveRequiredString($eventIdHeader, $payload['id'] ?? null);
        $eventType = $this->resolveRequiredString($eventTypeHeader, $payload['type'] ?? null);

        if ($eventId === null || $eventType === null) {
            throw new HttpException(400, 'Invalid webhook payload: missing event id or type.');
        }

        $generationData = $this->extractGenerationData($payload);
        $pixelforgeGenerationId = str_starts_with($eventType, 'generation.')
            ? $this->resolvePixelforgeGenerationId($generationData)
            : null;

        try {
            $event = WebhookEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'pixelforge_generation_id' => $pixelforgeGenerationId,
                'payload' => $payload,
                'processed_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return [
                'received' => true,
                'duplicate' => true,
            ];
        }

        ProcessPixelforgeWebhook::dispatch($event->id);

        return ['received' => true];
    }

    /**
     * Process a persisted webhook event (executed inside the queued job).
     */
    public function processStoredEvent(int $webhookEventId): void
    {
        $event = WebhookEvent::query()->find($webhookEventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $eventType = (string) $event->event_type;
        $generationData = $this->extractGenerationData($payload);

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

        $event->processed_at = now();
        $event->save();
    }

    /**
     * @param  array<string, mixed>  $parsedInput
     * @return array<string, mixed>
     */
    private function decodePayload(string $rawBody, array $parsedInput): array
    {
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);

            if (! is_array($decoded)) {
                throw new HttpException(400, 'Malformed JSON webhook body.');
            }

            return $decoded;
        }

        return $parsedInput;
    }

    private function resolveRequiredString(mixed $headerValue, mixed $payloadValue): ?string
    {
        if (is_string($headerValue) && trim($headerValue) !== '') {
            return trim($headerValue);
        }

        if (is_string($payloadValue) && trim($payloadValue) !== '') {
            return trim($payloadValue);
        }

        return null;
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

        return is_array($data) ? $data : [];
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
