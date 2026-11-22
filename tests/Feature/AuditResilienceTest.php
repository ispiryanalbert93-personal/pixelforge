<?php

namespace Tests\Feature;

use App\Actions\Wallet\HoldWalletFunds;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\WalletTransactionType;
use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\GenerationService;
use App\Services\Pixelforge\PricingCalculator;
use App\Services\Pixelforge\PricingService;
use App\Services\Pixelforge\UsageService;
use App\Services\Pixelforge\WebhookService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditResilienceTest extends TestCase
{
    use RefreshDatabase;

    private const array SAMPLE_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');
        Config::set('services.pixelforge.api_key', 'pf_test_secret_key');
        Config::set('services.pixelforge.webhook_secret', 'whsec_test_secret_123');

        Cache::put(PricingService::CACHE_KEY, $this->samplePricingConfig(), 3600);
        $this->app->instance(Client::class, new Client(retrySleepMs: 0));
    }

    public function test_timeout_after_provider_accepted_recovers_and_settles_exact_cost_when_webhook_arrives(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $user = User::factory()->withBalance(1_000_000_000)->create();

        /** @var GenerationService $service */
        $service = $this->app->make(GenerationService::class);
        $generation = $service->initiate($user, 'A lighthouse', ImageSize::Square, ImageQuality::Medium);

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('connection_error', $generation->error_code);
        $this->assertStringNotContainsString('cURL error', (string) $generation->error_message);
        $this->assertSame(1_000_000_000, $user->fresh()->wallet->balance_nanodollars);

        // Provider had accepted the request and delivers generation.succeeded webhook
        $payload = [
            'id' => 'evt_recover_1',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'data' => [
                'object' => [
                    'id' => 'gen_recovered_1',
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'metadata' => ['local_generation_id' => (string) $generation->id],
                    'usage' => [
                        'input_text_tokens' => 18,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 162,
                    ],
                    'rates' => self::SAMPLE_RATES,
                    'image_url' => 'https://cdn.pixelforge.test/recovered.png',
                    'completed_at' => '2026-11-22T12:00:00Z',
                ],
            ],
        ];

        $this->app->make(WebhookService::class)->handle(
            rawBody: json_encode($payload, JSON_THROW_ON_ERROR),
            eventIdHeader: 'evt_recover_1',
            eventTypeHeader: 'generation.succeeded',
        );

        $fresh = $generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $fresh->status);
        $this->assertSame('gen_recovered_1', $fresh->pixelforge_id);
        $this->assertSame(46_115_000, $fresh->actual_provider_cost_nano);
        $this->assertSame(57_643_750, $fresh->actual_user_cost_nano);
        $this->assertNotNull($fresh->settled_at);
        $this->assertSame(1_000_000_000 - 57_643_750, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);
    }

    public function test_terminal_content_policy_rejection_is_never_reopened_by_webhooks(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => ['code' => 'content_policy_violation', 'message' => 'Policy blocked.'],
            ], 400),
        ]);

        $user = User::factory()->withBalance(1_000_000_000)->create();
        $generation = $this->app->make(GenerationService::class)
            ->initiate($user, 'Blocked [blocked]', ImageSize::Square, ImageQuality::Low);

        $this->assertSame(GenerationStatus::Failed, $generation->status);

        $this->app->make(GenerationService::class)->updateFromProvider($generation, [
            'id' => 'gen_should_ignore',
            'status' => 'succeeded',
            'usage' => ['input_text_tokens' => 5, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 100],
            'rates' => self::SAMPLE_RATES,
        ]);

        $fresh = $generation->fresh();
        $this->assertSame(GenerationStatus::Failed, $fresh->status);
        $this->assertSame(0, $fresh->actual_user_cost_nano);
        $this->assertSame(1_000_000_000, $user->fresh()->wallet->balance_nanodollars);
    }

    public function test_rate_drift_on_202_invalidates_pricing_cache_locks_new_rates_and_adjusts_hold(): void
    {
        $higherRates = [
            'input_text_tokens' => '10.00',
            'input_image_tokens' => '20.00',
            'output_tokens' => '80.00',
            'reasoning_tokens' => '25.00',
        ];

        $freshPricingPayload = [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => $higherRates,
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

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_rate_drift',
                'status' => 'queued',
                'rates' => $higherRates,
            ], 202),
            'https://pixelforge.promptedit.dev/v1/pricing' => Http::response($freshPricingPayload, 200),
        ]);

        $user = User::factory()->withBalance(1_000_000_000)->create();
        $generation = $this->app->make(GenerationService::class)
            ->initiate($user, 'Rate drift test', ImageSize::Square, ImageQuality::Medium);

        $expectedNewEstimate = (new PricingCalculator)->estimate(
            prompt: 'Rate drift test',
            size: ImageSize::Square,
            quality: ImageQuality::Medium,
            referenceImageCount: 0,
            pricingConfig: [...$this->samplePricingConfig(), 'rates' => $higherRates],
        );

        $fresh = $generation->fresh();
        $this->assertSame($higherRates, $fresh->locked_rates);
        $this->assertSame($expectedNewEstimate->maxUserCostNano, $fresh->reserved_user_cost_nano);
        $this->assertSame($expectedNewEstimate->maxUserCostNano, $user->fresh()->wallet->reserved_nanodollars);
        $this->assertSame(1_000_000_000 - $expectedNewEstimate->maxUserCostNano, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(2, WalletTransaction::query()->where('type', WalletTransactionType::Hold)->count());
    }

    public function test_async_queue_retries_429_with_retry_after_and_preserves_hold(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => ['code' => 'concurrency_limit_exceeded', 'message' => 'Too many concurrent.'],
            ], 429, ['Retry-After' => '5']),
        ]);

        $user = User::factory()->withBalance(1_000_000_000)->create();
        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => null,
            'status' => GenerationStatus::Queued,
            'locked_rates' => self::SAMPLE_RATES,
            'estimated_max_user_cost_nano' => 50_000_000,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 50_000_000);

        $delay = $this->app->make(GenerationService::class)->submitGeneration(
            generationId: $generation->id,
            isAsyncQueue: true,
            attempt: 1,
            maxTries: 5,
        );

        $this->assertSame(5, $delay);

        $fresh = $generation->fresh();
        $this->assertSame(GenerationStatus::Queued, $fresh->status);
        $this->assertSame(50_000_000, $fresh->reserved_user_cost_nano);
        $this->assertNull($fresh->settled_at);
        $this->assertStringContainsString('retrying in 5s', (string) $fresh->error_message);
    }

    public function test_manual_sync_endpoint_reconciles_generation_and_enforces_ownership(): void
    {
        $owner = User::factory()->withBalance(1_000_000_000)->create();
        $other = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($owner)->create([
            'pixelforge_id' => 'gen_sync_1',
            'status' => GenerationStatus::Processing,
            'locked_rates' => self::SAMPLE_RATES,
            'estimated_max_user_cost_nano' => 75_000_000,
        ]);
        app(HoldWalletFunds::class)->handle($owner, $generation, 75_000_000);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations/gen_sync_1' => Http::response([
                'id' => 'gen_sync_1',
                'status' => 'succeeded',
                'image_url' => 'https://cdn.pixelforge.test/synced.png',
                'completed_at' => '2026-11-22T12:00:00Z',
                'usage' => [
                    'input_text_tokens' => 18,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 162,
                ],
            ], 200),
        ]);

        $this->actingAs($other)
            ->post(route('generations.sync', $generation))
            ->assertForbidden();

        $this->actingAs($owner)
            ->from('/generations/history')
            ->post(route('generations.sync', $generation))
            ->assertRedirect('/generations/history')
            ->assertSessionHas('success');

        $fresh = $generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $fresh->status);
        $this->assertSame(57_643_750, $fresh->actual_user_cost_nano);
        $this->assertSame(1_000_000_000 - 57_643_750, $owner->fresh()->wallet->balance_nanodollars);
    }

    public function test_reconcile_stuck_generations_command_syncs_old_active_generations(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        $stuck = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_stuck_old',
            'status' => GenerationStatus::Processing,
            'locked_rates' => self::SAMPLE_RATES,
            'created_at' => now()->subMinutes(15),
        ]);
        app(HoldWalletFunds::class)->handle($user, $stuck, 75_000_000);

        $recent = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_recent',
            'status' => GenerationStatus::Queued,
            'locked_rates' => self::SAMPLE_RATES,
            'created_at' => now()->subMinutes(2),
        ]);
        app(HoldWalletFunds::class)->handle($user, $recent, 75_000_000);

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations/gen_stuck_old' => Http::response([
                'id' => 'gen_stuck_old',
                'status' => 'failed',
                'error' => ['code' => 'internal_error', 'message' => 'Timed out upstream.'],
            ], 200),
        ]);

        $this->artisan('generations:reconcile', ['--minutes' => 10])->assertSuccessful();

        $this->assertSame(GenerationStatus::Failed, $stuck->fresh()->status);
        $this->assertSame(GenerationStatus::Queued, $recent->fresh()->status);
        $this->assertSame(1_000_000_000 - 75_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(75_000_000, $user->fresh()->wallet->reserved_nanodollars);
        Http::assertSentCount(1);
    }

    public function test_webhook_dispatches_queued_job_and_deduplicates_before_processing(): void
    {
        Queue::fake();

        $payload = [
            'id' => 'evt_queued_1',
            'object' => 'event',
            'type' => 'ping',
            'data' => ['message' => 'pong'],
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = time();
        $sig = 't='.$ts.',v1='.hash_hmac('sha256', "{$ts}.{$rawBody}", 'whsec_test_secret_123');

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $sig,
            'HTTP_PIXELFORGE_EVENT_ID' => 'evt_queued_1',
            'HTTP_PIXELFORGE_EVENT_TYPE' => 'ping',
        ];

        $this->call('POST', '/api/webhooks/pixelforge', [], [], [], $headers, $rawBody)
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->call('POST', '/api/webhooks/pixelforge', [], [], [], $headers, $rawBody)
            ->assertOk()
            ->assertJson(['received' => true, 'duplicate' => true]);

        Queue::assertPushed(ProcessPixelforgeWebhook::class, 1);
    }

    public function test_usage_service_caches_successful_reports_for_60_seconds(): void
    {
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-02',
                'data' => [],
            ], 200),
        ]);

        /** @var UsageService $usageService */
        $usageService = $this->app->make(UsageService::class);
        $usageService->getUsageReport('2026-11-01', '2026-11-02');
        $usageService->getUsageReport('2026-11-01', '2026-11-02');

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function samplePricingConfig(): array
    {
        return [
            'rates' => self::SAMPLE_RATES,
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'tokens_per_reference_image' => 765,
            'max_reference_images' => 4,
            'markup_percent' => Money::DEFAULT_MARKUP_PERCENT,
            'sizes' => array_map(
                static fn (ImageSize $size): array => [
                    'value' => $size->value,
                    'label' => $size->label(),
                    'is_wide_or_tall' => $size->isWideOrTall(),
                ],
                ImageSize::cases(),
            ),
            'qualities' => [
                [
                    'value' => 'low',
                    'label' => 'Low',
                    'reasoning_tokens' => ['min' => 0, 'midpoint' => 250, 'max' => 500],
                    'output_tokens' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                ],
                [
                    'value' => 'medium',
                    'label' => 'Medium',
                    'reasoning_tokens' => ['min' => 200, 'midpoint' => 1100, 'max' => 2000],
                    'output_tokens' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                ],
                [
                    'value' => 'high',
                    'label' => 'High',
                    'reasoning_tokens' => ['min' => 1000, 'midpoint' => 4500, 'max' => 8000],
                    'output_tokens' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
            ],
        ];
    }
}
