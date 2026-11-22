<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class PixelforgeApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $rawError
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly string $errorMessage,
        public readonly ?int $retryAfterSeconds = null,
        public readonly array $rawError = [],
    ) {
        parent::__construct(
            sprintf('Pixelforge API error (%d %s): %s', $httpStatus, $errorCode, $errorMessage),
            $httpStatus,
        );
    }

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $payload = $response->json();
        $payloadArray = is_array($payload) ? $payload : [];
        $error = is_array($payloadArray['error'] ?? null) ? $payloadArray['error'] : [];

        $code = (string) ($error['code'] ?? ($status === 503 ? 'overloaded' : 'api_error'));
        $message = (string) ($error['message'] ?? ($response->body() !== '' ? $response->body() : "Pixelforge API error ({$status})."));

        $retryAfterHeader = $response->header('Retry-After');
        $retryAfterSeconds = is_numeric($retryAfterHeader)
            ? max(0, (int) $retryAfterHeader)
            : null;

        return new self(
            httpStatus: $status,
            errorCode: $code,
            errorMessage: $message,
            retryAfterSeconds: $retryAfterSeconds,
            rawError: $error !== [] ? $error : $payloadArray,
        );
    }

    public function isRetryableRateOrConcurrencyLimit(): bool
    {
        return $this->httpStatus === 429
            && in_array($this->errorCode, ['concurrency_limit_exceeded', 'rate_limit_exceeded'], true);
    }

    public function isOverloaded(): bool
    {
        return $this->httpStatus === 503 || $this->errorCode === 'overloaded';
    }

    public function isTerminalRejection(): bool
    {
        return $this->httpStatus >= 400
            && $this->httpStatus < 500
            && ! $this->isRetryableRateOrConcurrencyLimit();
    }

    public function isTransientServerError(): bool
    {
        return $this->httpStatus >= 500;
    }
}
