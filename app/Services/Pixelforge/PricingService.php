<?php

namespace App\Services\Pixelforge;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PixelforgeApiException;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Fetches and caches live Pixelforge pricing configuration strictly from GET /v1/pricing.
 *
 * @phpstan-type PricingRates array{
 *     input_text_tokens: string,
 *     input_image_tokens: string,
 *     output_tokens: string,
 *     reasoning_tokens: string,
 * }
 * @phpstan-type QualityConfig array{
 *     value: string,
 *     label: string,
 *     reasoning_tokens: array{min: int, midpoint: int, max: int},
 *     output_tokens: array<string, int>,
 * }
 * @phpstan-type PricingConfigPayload array{
 *     rates: PricingRates,
 *     characters_per_text_token: int,
 *     text_token_variance: float,
 *     tokens_per_reference_image: int,
 *     max_reference_images: int,
 *     markup_percent: int,
 *     sizes: array<int, array{value: string, label: string, is_wide_or_tall: bool}>,
 *     qualities: array<int, QualityConfig>,
 * }
 */
class PricingService
{
    public const string CACHE_KEY = 'pixelforge:pricing_config';

    public const int DEFAULT_CACHE_TTL_SECONDS = 3600;

    /**
     * @var list<string>
     */
    private const array REQUIRED_RATE_KEYS = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * Get the live pricing configuration from Cache or GET /v1/pricing.
     *
     * @return PricingConfigPayload
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function getPricingConfig(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($this->isValidCachedConfig($cached)) {
            return $cached;
        }

        $payload = $this->client->getPricing();
        $normalized = $this->normalizePayload($payload);
        $ttl = max(1, (int) config('services.pixelforge.pricing_cache_ttl', self::DEFAULT_CACHE_TTL_SECONDS));

        Cache::put(self::CACHE_KEY, $normalized, $ttl);

        return $normalized;
    }

    /**
     * @return PricingRates
     *
     * @throws PixelforgeApiException
     * @throws ConnectionException
     */
    public function currentRates(): array
    {
        return $this->getPricingConfig()['rates'];
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Validate and normalize a raw GET /v1/pricing payload.
     *
     * @param  array<string, mixed>  $payload
     * @return PricingConfigPayload
     *
     * @throws PixelforgeApiException
     */
    private function normalizePayload(array $payload): array
    {
        $rates = $this->parseRates($payload);
        $charactersPerTextToken = $this->requireInt(data_get($payload, 'tokens.characters_per_text_token'), 'tokens.characters_per_text_token', min: 1);
        $textTokenVariance = $this->parseVariance(data_get($payload, 'tokens.text_token_variance'));
        $tokensPerReferenceImage = $this->requireInt(data_get($payload, 'tokens.per_reference_image'), 'tokens.per_reference_image', min: 0);

        $sizes = array_map(
            static fn (ImageSize $size): array => [
                'value' => $size->value,
                'label' => $size->label(),
                'is_wide_or_tall' => $size->isWideOrTall(),
            ],
            ImageSize::cases(),
        );

        $qualities = array_map(
            fn (ImageQuality $quality): array => $this->parseQuality($quality, $payload),
            ImageQuality::cases(),
        );

        return [
            'rates' => $rates,
            'characters_per_text_token' => $charactersPerTextToken,
            'text_token_variance' => $textTokenVariance,
            'tokens_per_reference_image' => $tokensPerReferenceImage,
            'max_reference_images' => PricingCalculator::MAX_REFERENCE_IMAGES,
            'markup_percent' => Money::DEFAULT_MARKUP_PERCENT,
            'sizes' => $sizes,
            'qualities' => $qualities,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return PricingRates
     *
     * @throws PixelforgeApiException
     */
    private function parseRates(array $payload): array
    {
        $rates = [];

        foreach (self::REQUIRED_RATE_KEYS as $key) {
            $raw = data_get($payload, "rates.{$key}");

            if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
                throw $this->invalidPayloadException("Missing or invalid pricing rate [{$key}].");
            }

            $rate = ltrim(trim((string) $raw), '+');

            try {
                $nanoPerToken = Money::ratePerMillionToNanoPerToken($rate);
            } catch (InvalidArgumentException) {
                throw $this->invalidPayloadException("Malformed pricing rate [{$key}]: {$rate}.");
            }

            if (bccomp($nanoPerToken, '0', 6) < 0) {
                throw $this->invalidPayloadException("Negative pricing rate [{$key}] is not allowed.");
            }

            $rates[$key] = $rate;
        }

        return $rates;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return QualityConfig
     *
     * @throws PixelforgeApiException
     */
    private function parseQuality(ImageQuality $quality, array $payload): array
    {
        $key = $quality->value;
        $outputTokens = [];

        foreach (ImageSize::cases() as $size) {
            $outputTokens[$size->value] = $this->requireInt(
                data_get($payload, "tokens.output.{$key}.{$size->value}"),
                "tokens.output.{$key}.{$size->value}",
                min: 0,
            );
        }

        $min = $this->requireInt(data_get($payload, "tokens.reasoning.{$key}.min"), "tokens.reasoning.{$key}.min", min: 0);
        $max = $this->requireInt(data_get($payload, "tokens.reasoning.{$key}.max"), "tokens.reasoning.{$key}.max", min: $min);

        return [
            'value' => $key,
            'label' => $quality->label(),
            'reasoning_tokens' => [
                'min' => $min,
                'midpoint' => intdiv($min + $max, 2),
                'max' => $max,
            ],
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * @throws PixelforgeApiException
     */
    private function parseVariance(mixed $value): float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric(trim($value)))) {
            throw $this->invalidPayloadException('Missing or invalid [tokens.text_token_variance].');
        }

        $variance = (float) $value;

        if (! ($variance >= 0 && $variance < 1)) {
            throw $this->invalidPayloadException('Pricing [tokens.text_token_variance] must be between 0 and 1.');
        }

        return $variance;
    }

    /**
     * @throws PixelforgeApiException
     */
    private function requireInt(mixed $value, string $field, int $min = 0): int
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match('/^-?\d+$/', $trimmed) !== 1) {
                throw $this->invalidPayloadException("Invalid integer for [{$field}].");
            }
            $value = (int) $trimmed;
        }

        if (! is_int($value) || $value < $min) {
            throw $this->invalidPayloadException("Missing or out-of-range integer for [{$field}].");
        }

        return $value;
    }

    /**
     * @phpstan-assert-if-true PricingConfigPayload $cached
     */
    private function isValidCachedConfig(mixed $cached): bool
    {
        return is_array($cached)
            && isset(
                $cached['rates'],
                $cached['characters_per_text_token'],
                $cached['text_token_variance'],
                $cached['tokens_per_reference_image'],
                $cached['max_reference_images'],
                $cached['markup_percent'],
                $cached['qualities'],
                $cached['sizes'],
            );
    }

    private function invalidPayloadException(string $message): PixelforgeApiException
    {
        return new PixelforgeApiException(
            httpStatus: 502,
            errorCode: 'invalid_pricing_payload',
            errorMessage: $message,
        );
    }
}
