<?php

namespace App\Services\Pixelforge;

use App\Exceptions\PixelforgeApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class Client
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $apiKey = null,
        private readonly ?int $retrySleepMs = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function sendGeneration(array $payload, string $idempotencyKey): array
    {
        $body = [
            'prompt' => $payload['prompt'],
            'size' => $payload['size'],
            'quality' => $payload['quality'],
        ];

        if (! empty($payload['reference_images']) && is_array($payload['reference_images'])) {
            $body['reference_images'] = array_values($payload['reference_images']);
        }

        if (! empty($payload['metadata']) && is_array($payload['metadata'])) {
            $body['metadata'] = $payload['metadata'];
        }

        return $this->handleResponse($this->request($idempotencyKey)->post('/generations', $body));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function getGeneration(string $pixelforgeId): array
    {
        return $this->handleResponse($this->request()->get('/generations/'.rawurlencode($pixelforgeId)));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function getPricing(): array
    {
        return $this->handleResponse($this->request()->get('/pricing'));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function getUsage(?string $startDate = null, ?string $endDate = null): array
    {
        $query = array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $this->handleResponse($this->request()->get('/usage', $query));
    }

    private function request(?string $idempotencyKey = null): PendingRequest
    {
        if (! config('services.pixelforge.requests_enabled', false)) {
            throw new PixelforgeApiException(
                httpStatus: 0,
                errorCode: 'pixelforge_requests_disabled',
                errorMessage: 'Outbound Pixelforge API requests are disabled by configuration.',
            );
        }

        $pending = Http::baseUrl($this->resolveBaseUrl())
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5)
            ->retry(
                times: 2,
                sleepMilliseconds: fn (int $attempt, Throwable $exception): int => $this->resolveRetryDelayMs($attempt, $exception),
                when: static function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        return $exception->response->status() === 503
                            || $exception->response->json('error.code') === 'overloaded';
                    }

                    return false;
                },
                throw: false,
            );

        $apiKey = $this->resolveApiKey();
        if ($apiKey !== '') {
            $pending->withToken($apiKey);
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $pending->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $pending;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    private function handleResponse(Response $response): array
    {
        if ($response->failed()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveBaseUrl(): string
    {
        return rtrim(
            (string) ($this->baseUrl ?? config('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1')),
            '/',
        );
    }

    private function resolveApiKey(): string
    {
        return (string) ($this->apiKey ?? config('services.pixelforge.api_key', ''));
    }

    private function resolveRetryDelayMs(int $attempt, Throwable $exception): int
    {
        if ($this->retrySleepMs !== null) {
            return $this->retrySleepMs;
        }

        if (app()->environment('testing')) {
            return 0;
        }

        if ($exception instanceof RequestException) {
            $retryAfter = $exception->response->header('Retry-After');
            if (is_numeric($retryAfter)) {
                return min(5000, max(200, (int) $retryAfter * 1000));
            }
        }

        return 200 * $attempt;
    }
}
