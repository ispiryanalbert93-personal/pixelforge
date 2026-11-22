<?php

namespace App\Services\Pixelforge;

use App\Exceptions\PixelforgeApiException;
use Throwable;

final class ErrorFormatter
{
    public static function forPricing(Throwable $exception): string
    {
        if ($exception instanceof PixelforgeApiException) {
            return match ($exception->errorCode) {
                'overloaded' => 'Pixelforge pricing service is temporarily overloaded. Please retry in a moment.',
                'rate_limit_exceeded' => 'Pixelforge pricing requests are temporarily rate-limited. Please retry shortly.',
                'invalid_api_key', 'pixelforge_requests_disabled' => 'Pixelforge API is currently unavailable due to server configuration.',
                'invalid_pricing_payload' => 'Pixelforge returned an unexpected pricing response. Please retry shortly.',
                default => 'Unable to load current Pixelforge pricing right now. Please retry.',
            };
        }

        return 'Could not reach Pixelforge to load current pricing. Please retry.';
    }

    public static function forGeneration(string $errorCode, ?string $providerMessage = null): string
    {
        return match ($errorCode) {
            'content_policy_violation' => self::cleanProviderMessage(
                $providerMessage,
                'Your prompt was rejected by Pixelforge safety policy. You were not charged ($0.00).',
            ),
            'validation_failed' => self::cleanProviderMessage(
                $providerMessage,
                'The generation request parameters were rejected. Please check your prompt and reference URLs.',
            ),
            'daily_limit_exceeded' => 'Daily Pixelforge generation quota has been reached. Please try again tomorrow (UTC).',
            'rate_limit_exceeded' => 'Pixelforge rate limit was reached after retries. Held credits were released ($0.00 charged); please retry shortly.',
            'concurrency_limit_exceeded' => 'Pixelforge concurrent generation capacity is full. Held credits were released ($0.00 charged); please retry shortly.',
            'overloaded' => 'Pixelforge is temporarily overloaded. Held credits were released ($0.00 charged); please retry.',
            'internal_error' => 'Something went wrong on Pixelforge\'s side while generating. You were not charged ($0.00); please retry.',
            'generation_failed' => self::cleanProviderMessage(
                $providerMessage,
                'Pixelforge could not generate an image for this prompt. Held credits were released ($0.00 charged).',
            ),
            'connection_error' => 'Connection to Pixelforge timed out before confirmation. Held credits were released; if Pixelforge still completes it, your history will update automatically.',
            'invalid_api_key', 'pixelforge_requests_disabled' => 'Image generation is temporarily unavailable due to a server configuration issue. You were not charged.',
            default => 'Generation could not be completed. Held credits were released ($0.00 charged); please retry.',
        };
    }

    private static function cleanProviderMessage(?string $message, string $fallback): string
    {
        if ($message === null) {
            return $fallback;
        }

        $trimmed = trim($message);

        if ($trimmed === '' || str_contains($trimmed, '<html') || str_contains($trimmed, 'cURL error')) {
            return $fallback;
        }

        return $trimmed;
    }
}
