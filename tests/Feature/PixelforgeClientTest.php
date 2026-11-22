<?php

namespace Tests\Feature;

use App\Exceptions\PixelforgeApiException;
use App\Services\Pixelforge\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    use RefreshDatabase;

    private const array SAMPLE_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');
        Config::set('services.pixelforge.api_key', 'pf_test_secret_key');

        $this->client = new Client(retrySleepMs: 0);
    }

    public function test_create_generation_sends_documented_payload_and_parses_202_response(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_123',
                'status' => 'queued',
                'size' => '1024x1024',
                'quality' => 'medium',
                'rates' => self::SAMPLE_RATES,
                'created_at' => '2026-11-21T12:00:00Z',
            ], 202),
        ]);

        $response = $this->client->sendGeneration([
            'prompt' => 'A cyberpunk lighthouse at dusk',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [
                'first' => 'https://example.com/ref1.png',
                'second' => 'https://example.com/ref2.png',
            ],
            'metadata' => [
                'local_generation_id' => 'local-uuid-123',
                'user_id' => '42',
            ],
            'unknown_extra_key' => 'must_not_be_sent',
        ], 'idem-key-123');

        $this->assertSame('gen_123', $response['id']);
        $this->assertSame('queued', $response['status']);
        $this->assertSame(self::SAMPLE_RATES, $response['rates']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
                && $request->hasHeader('Authorization', 'Bearer pf_test_secret_key')
                && $request->hasHeader('Idempotency-Key', 'idem-key-123')
                && ! array_key_exists('unknown_extra_key', $body)
                && $body['prompt'] === 'A cyberpunk lighthouse at dusk'
                && $body['size'] === '1024x1024'
                && $body['quality'] === 'medium'
                && $body['reference_images'] === [
                    'https://example.com/ref1.png',
                    'https://example.com/ref2.png',
                ]
                && $body['metadata'] === [
                    'local_generation_id' => 'local-uuid-123',
                    'user_id' => '42',
                ];
        });
    }

    public function test_create_generation_omits_empty_reference_images(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_no_refs',
                'status' => 'queued',
                'rates' => self::SAMPLE_RATES,
            ], 202),
        ]);

        $this->client->sendGeneration([
            'prompt' => 'Minimalist mountain sunrise',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'metadata' => ['local_generation_id' => 'uuid-1'],
        ], 'idem-no-refs');

        Http::assertSent(function (Request $request): bool {
            return ! array_key_exists('reference_images', $request->data());
        });
    }

    public function test_create_generation_retries_transient_503_overloaded_with_same_idempotency_key(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::sequence()
                ->push([
                    'error' => [
                        'type' => 'api_error',
                        'code' => 'overloaded',
                        'message' => 'Pixelforge is temporarily overloaded.',
                    ],
                ], 503)
                ->push([
                    'id' => 'gen_after_503',
                    'status' => 'queued',
                    'rates' => self::SAMPLE_RATES,
                ], 202),
        ]);

        $response = $this->client->sendGeneration([
            'prompt' => 'Stormy ocean waves',
            'size' => '1536x1024',
            'quality' => 'high',
        ], 'idem-retry-503');

        $this->assertSame('gen_after_503', $response['id']);
        Http::assertSentCount(2);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $this->assertSame(['idem-retry-503'], $recorded[0][0]->header('Idempotency-Key'));
        $this->assertSame(['idem-retry-503'], $recorded[1][0]->header('Idempotency-Key'));
    }

    public function test_create_generation_throws_pixelforge_api_exception_on_400_content_policy_violation(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'Your prompt was flagged by our safety system.',
                ],
            ], 400),
        ]);

        try {
            $this->client->sendGeneration([
                'prompt' => 'Blocked prompt',
                'size' => '1024x1024',
                'quality' => 'medium',
            ], 'idem-400');

            $this->fail('Expected PixelforgeApiException was not thrown.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(400, $e->httpStatus);
            $this->assertSame('content_policy_violation', $e->errorCode);
            $this->assertSame('Your prompt was flagged by our safety system.', $e->errorMessage);
            $this->assertNull($e->retryAfterSeconds);
            $this->assertTrue($e->isTerminalRejection());
            $this->assertFalse($e->isRetryableRateOrConcurrencyLimit());
            $this->assertFalse($e->isOverloaded());
        }

        Http::assertSentCount(1);
    }

    public function test_create_generation_throws_pixelforge_api_exception_on_422_validation_failed(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'validation_failed',
                    'message' => 'Invalid size parameter.',
                ],
            ], 422),
        ]);

        try {
            $this->client->sendGeneration([
                'prompt' => 'Test prompt',
                'size' => '999x999',
                'quality' => 'medium',
            ], 'idem-422');

            $this->fail('Expected PixelforgeApiException was not thrown.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertSame('Invalid size parameter.', $e->errorMessage);
            $this->assertTrue($e->isTerminalRejection());
        }

        Http::assertSentCount(1);
    }

    public function test_create_generation_throws_pixelforge_api_exception_on_429_concurrency_and_rate_limits(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::sequence()
                ->push([
                    'error' => [
                        'type' => 'rate_limit_error',
                        'code' => 'concurrency_limit_exceeded',
                        'message' => 'Too many concurrent generations.',
                    ],
                ], 429, ['Retry-After' => '5'])
                ->push([
                    'error' => [
                        'type' => 'rate_limit_error',
                        'code' => 'rate_limit_exceeded',
                        'message' => 'Requests per minute limit reached.',
                    ],
                ], 429, ['Retry-After' => '12'])
                ->push([
                    'error' => [
                        'type' => 'rate_limit_error',
                        'code' => 'daily_limit_exceeded',
                        'message' => 'Daily generation quota reached.',
                    ],
                ], 429),
        ]);

        try {
            $this->client->sendGeneration([
                'prompt' => 'Concurrency test',
                'size' => '1024x1024',
                'quality' => 'medium',
            ], 'idem-429-1');
            $this->fail('Expected PixelforgeApiException for concurrency_limit_exceeded.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame('concurrency_limit_exceeded', $e->errorCode);
            $this->assertSame(5, $e->retryAfterSeconds);
            $this->assertTrue($e->isRetryableRateOrConcurrencyLimit());
            $this->assertFalse($e->isTerminalRejection());
        }

        try {
            $this->client->sendGeneration([
                'prompt' => 'Rate limit test',
                'size' => '1024x1024',
                'quality' => 'medium',
            ], 'idem-429-2');
            $this->fail('Expected PixelforgeApiException for rate_limit_exceeded.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame('rate_limit_exceeded', $e->errorCode);
            $this->assertSame(12, $e->retryAfterSeconds);
            $this->assertTrue($e->isRetryableRateOrConcurrencyLimit());
            $this->assertFalse($e->isTerminalRejection());
        }

        try {
            $this->client->sendGeneration([
                'prompt' => 'Daily limit test',
                'size' => '1024x1024',
                'quality' => 'medium',
            ], 'idem-429-3');
            $this->fail('Expected PixelforgeApiException for daily_limit_exceeded.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame('daily_limit_exceeded', $e->errorCode);
            $this->assertNull($e->retryAfterSeconds);
            $this->assertFalse($e->isRetryableRateOrConcurrencyLimit());
            $this->assertTrue($e->isTerminalRejection());
        }
    }

    public function test_create_generation_throws_pixelforge_api_exception_when_503_overloaded_persists(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'code' => 'overloaded',
                    'message' => 'Pixelforge is temporarily overloaded.',
                ],
            ], 503, ['Retry-After' => '7']),
        ]);

        try {
            $this->client->sendGeneration([
                'prompt' => 'Persistent overload',
                'size' => '1024x1024',
                'quality' => 'medium',
            ], 'idem-503-exhausted');

            $this->fail('Expected PixelforgeApiException when 503 persists.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(503, $e->httpStatus);
            $this->assertSame('overloaded', $e->errorCode);
            $this->assertSame('Pixelforge is temporarily overloaded.', $e->errorMessage);
            $this->assertSame(7, $e->retryAfterSeconds);
            $this->assertTrue($e->isOverloaded());
            $this->assertFalse($e->isTerminalRejection());
        }

        Http::assertSentCount(2);
    }

    public function test_get_generation_fetches_generation_payload(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations/gen_123' => Http::response([
                'id' => 'gen_123',
                'status' => 'succeeded',
                'image_url' => 'https://cdn.pixelforge.test/gen_123.png',
            ], 200),
        ]);

        $generation = $this->client->getGeneration('gen_123');

        $this->assertSame('gen_123', $generation['id']);
        $this->assertSame('succeeded', $generation['status']);
        $this->assertSame('https://cdn.pixelforge.test/gen_123.png', $generation['image_url']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations/gen_123'
                && $request->hasHeader('Authorization', 'Bearer pf_test_secret_key');
        });
    }

    public function test_get_pricing_fetches_pricing_configuration(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
                'rates' => self::SAMPLE_RATES,
                'currency' => 'USD',
            ], 200),
        ]);

        $pricing = $this->client->getPricing();

        $this->assertSame(self::SAMPLE_RATES, $pricing['rates']);
        $this->assertSame('USD', $pricing['currency']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://pixelforge.promptedit.dev/v1/pricing'
                && $request->hasHeader('Authorization', 'Bearer pf_test_secret_key');
        });
    }

    public function test_get_usage_fetches_usage_with_optional_date_filters(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/usage*' => Http::sequence()
                ->push(['total_generations' => 10], 200)
                ->push(['total_generations' => 4, 'start_date' => '2026-11-01', 'end_date' => '2026-11-21'], 200),
        ]);

        $allUsage = $this->client->getUsage();
        $filteredUsage = $this->client->getUsage('2026-11-01', '2026-11-21');

        $this->assertSame(10, $allUsage['total_generations']);
        $this->assertSame(4, $filteredUsage['total_generations']);

        $recorded = Http::recorded();
        $this->assertSame('https://pixelforge.promptedit.dev/v1/usage', $recorded[0][0]->url());
        $this->assertSame(
            'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-01&end_date=2026-11-21',
            $recorded[1][0]->url()
        );
    }

    public function test_blocks_all_outbound_requests_when_requests_enabled_config_is_false(): void
    {
        Http::fake();
        Config::set('services.pixelforge.requests_enabled', false);

        try {
            $this->client->getPricing();
            $this->fail('Expected PixelforgeApiException when Pixelforge requests are disabled.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(0, $e->httpStatus);
            $this->assertSame('pixelforge_requests_disabled', $e->errorCode);
            $this->assertFalse($e->isTransientServerError());
            $this->assertFalse($e->isOverloaded());
        }

        Http::assertNothingSent();
    }
}
