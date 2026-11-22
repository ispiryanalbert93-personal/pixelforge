<?php

namespace Tests\Feature;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\PixelforgeApiException;
use App\Models\User;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\GenerationService;
use App\Services\Pixelforge\PricingCalculator;
use App\Services\Pixelforge\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PixelforgePricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');
        Config::set('services.pixelforge.api_key', '');

        $this->app->instance(Client::class, new Client(retrySleepMs: 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_default_pricing_cache_ttl_is_one_hour(): void
    {
        $this->assertSame(3600, PricingService::DEFAULT_CACHE_TTL_SECONDS);
        $this->assertSame(3600, config('services.pixelforge.pricing_cache_ttl'));
    }

    public function test_fetches_and_caches_live_pricing_for_one_hour_and_uses_live_tokens_in_generation_initiate(): void
    {
        Carbon::setTestNow('2026-11-22T12:00:00Z');

        $livePayload = [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '8.00',
                'input_image_tokens' => '16.00',
                'output_tokens' => '60.00',
                'reasoning_tokens' => '20.00',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 800,
                'output' => [
                    'low' => ['1024x1024' => 300, '1536x1024' => 450, '1024x1536' => 450],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 10, 'max' => 410],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/pricing' => Http::response($livePayload, 200),
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_live_pricing_1',
                'status' => 'queued',
            ], 202),
        ]);

        /** @var PricingService $pricingService */
        $pricingService = $this->app->make(PricingService::class);

        $first = $pricingService->getPricingConfig();
        $second = $pricingService->getPricingConfig();

        $this->assertSame('8.00', $first['rates']['input_text_tokens']);
        $this->assertSame('60.00', $first['rates']['output_tokens']);
        $this->assertSame(800, $first['tokens_per_reference_image']);
        $this->assertSame(300, $first['qualities'][0]['output_tokens']['1024x1024']);
        $this->assertSame(['min' => 10, 'midpoint' => 210, 'max' => 410], $first['qualities'][0]['reasoning_tokens']);
        $this->assertSame($first, $second);

        Http::assertSentCount(1);

        // Still cached 59 minutes later
        Carbon::setTestNow('2026-11-22T12:59:00Z');
        $stillCached = $pricingService->getPricingConfig();
        $this->assertSame($first, $stillCached);
        Http::assertSentCount(1);

        $user = User::factory()->withBalance(5_000_000_000)->create();

        /** @var GenerationService $generationService */
        $generationService = $this->app->make(GenerationService::class);
        $generation = $generationService->initiate(
            user: $user,
            prompt: 'Live pricing test',
            size: ImageSize::Square,
            quality: ImageQuality::Low,
            referenceImages: ['https://example.com/ref.png'],
        );

        $expectedEstimate = (new PricingCalculator)->estimate(
            prompt: 'Live pricing test',
            size: ImageSize::Square,
            quality: ImageQuality::Low,
            referenceImageCount: 1,
            pricingConfig: $first,
        );

        $this->assertSame($expectedEstimate->maxUserCostNano, $generation->reserved_user_cost_nano);
        $this->assertSame('60.00', $generation->locked_rates['output_tokens']);

        // Expires after 1 hour (3600s) and refreshes from API
        Carbon::setTestNow('2026-11-22T13:01:00Z');
        $pricingService->getPricingConfig();
        Http::assertSentCount(3); // 1st pricing + 1 generation + refreshed pricing
    }

    public function test_throws_without_fallback_and_without_caching_when_pricing_api_fails(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/pricing' => Http::sequence()
                ->push(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)
                ->push(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)
                ->push($this->documentedPricingPayload('7.50'), 200),
        ]);

        /** @var PricingService $pricingService */
        $pricingService = $this->app->make(PricingService::class);

        try {
            $pricingService->getPricingConfig();
            $this->fail('Expected PixelforgeApiException when /v1/pricing fails.');
        } catch (PixelforgeApiException $exception) {
            $this->assertSame(503, $exception->httpStatus);
            $this->assertSame('overloaded', $exception->errorCode);
        }

        $this->assertNull(Cache::get(PricingService::CACHE_KEY));

        $recovered = $pricingService->getPricingConfig();
        $this->assertSame('7.50', $recovered['rates']['input_text_tokens']);
        $this->assertNotNull(Cache::get(PricingService::CACHE_KEY));
    }

    public function test_rejects_malformed_or_negative_remote_pricing_without_fallback(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
                'rates' => [
                    'input_text_tokens' => '-5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'tokens' => $this->documentedPricingPayload()['tokens'],
            ], 200),
        ]);

        /** @var PricingService $pricingService */
        $pricingService = $this->app->make(PricingService::class);

        try {
            $pricingService->getPricingConfig();
            $this->fail('Expected PixelforgeApiException on negative rate.');
        } catch (PixelforgeApiException $exception) {
            $this->assertSame('invalid_pricing_payload', $exception->errorCode);
        }

        $this->assertNull(Cache::get(PricingService::CACHE_KEY));
    }

    public function test_throws_without_http_when_requests_disabled(): void
    {
        Http::fake();
        Config::set('services.pixelforge.requests_enabled', false);

        /** @var PricingService $pricingService */
        $pricingService = $this->app->make(PricingService::class);

        $this->expectException(PixelforgeApiException::class);

        try {
            $pricingService->getPricingConfig();
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function documentedPricingPayload(string $inputTextRate = '5.00'): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => $inputTextRate,
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }
}
