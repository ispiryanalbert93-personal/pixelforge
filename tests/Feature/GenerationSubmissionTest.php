<?php

namespace Tests\Feature;

use App\Actions\Wallet\HoldWalletFunds;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\GenerationService;
use App\Services\Pixelforge\PricingCalculator;
use App\Services\Pixelforge\PricingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const array SAMPLE_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private GenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');
        Config::set('services.pixelforge.api_key', 'pf_test_secret_key');

        Cache::put(PricingService::CACHE_KEY, $this->samplePricingConfig(), 3600);

        $this->app->instance(Client::class, new Client(retrySleepMs: 0));
        $this->service = $this->app->make(GenerationService::class);
    }

    public function test_initiate_happy_path_reserves_max_cost_and_submits_to_pixelforge(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();
        $prompt = 'A neon-lit ramen shop in Tokyo rain';
        $referenceImages = ['https://example.com/ref-1.png'];

        $estimate = (new PricingCalculator)->estimate(
            prompt: $prompt,
            size: ImageSize::Square,
            quality: ImageQuality::Medium,
            referenceImageCount: 1,
            pricingConfig: $this->samplePricingConfig(),
        );

        $providerRates = [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_abc123',
                'status' => 'queued',
                'size' => '1024x1024',
                'quality' => 'medium',
                'rates' => $providerRates,
            ], 202),
        ]);

        $generation = $this->service->initiate(
            user: $user,
            prompt: $prompt,
            size: ImageSize::Square,
            quality: ImageQuality::Medium,
            referenceImages: $referenceImages,
        );

        $this->assertIsInt($generation->id);
        $this->assertGreaterThan(0, $generation->id);
        $this->assertTrue(Str::isUuid($generation->idempotency_key));
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_abc123', $generation->pixelforge_id);
        $this->assertSame($providerRates, $generation->locked_rates);
        $this->assertSame($estimate->maxUserCostNano, $generation->estimated_max_user_cost_nano);
        $this->assertSame($estimate->maxUserCostNano, $generation->reserved_user_cost_nano);
        $this->assertNull($generation->error_code);
        $this->assertNull($generation->error_message);

        $freshWallet = $user->fresh()->wallet;
        $this->assertSame(1_000_000_000 - $estimate->maxUserCostNano, $freshWallet->balance_nanodollars);
        $this->assertSame($estimate->maxUserCostNano, $freshWallet->reserved_nanodollars);

        $this->assertDatabaseCount('wallet_transactions', 1);
        $holdEntry = WalletTransaction::query()->firstOrFail();
        $this->assertSame(WalletTransactionType::Hold, $holdEntry->type);
        $this->assertSame(-$estimate->maxUserCostNano, $holdEntry->amount_nanodollars);

        Http::assertSent(function (Request $request) use ($generation, $user): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
                && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
                && $body['prompt'] === $generation->prompt
                && $body['size'] === '1024x1024'
                && $body['quality'] === 'medium'
                && $body['reference_images'] === ['https://example.com/ref-1.png']
                && $body['metadata'] === [
                    'local_generation_id' => (string) $generation->id,
                    'user_id' => (string) $user->id,
                ];
        });
    }

    public function test_initiate_rejects_insufficient_credits_without_sending_http_or_leaving_generation(): void
    {
        $user = User::factory()->withBalance(1_000_000)->create(); // $0.001, far below medium square max cost

        Http::fake();

        try {
            $this->service->initiate(
                user: $user,
                prompt: 'Expensive image',
                size: ImageSize::Square,
                quality: ImageQuality::Medium,
                referenceImages: ['https://example.com/ref-1.png'],
            );

            $this->fail('Expected InsufficientCreditsException was not thrown.');
        } catch (InsufficientCreditsException $exception) {
            $this->assertSame(1_000_000, $exception->availableNano);
            $this->assertGreaterThan(1_000_000, $exception->requiredNano);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(1_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);
    }

    public function test_initiate_handles_content_policy_violation_by_failing_and_refunding_full_hold(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'Prompt violates safety policy [blocked].',
                ],
            ], 400),
        ]);

        $generation = $this->service->initiate(
            user: $user,
            prompt: 'Something [blocked]',
            size: ImageSize::Square,
            quality: ImageQuality::Medium,
        );

        $freshGen = $generation->fresh();
        $freshWallet = $user->fresh()->wallet;

        $this->assertSame(GenerationStatus::Failed, $freshGen->status);
        $this->assertNull($freshGen->pixelforge_id);
        $this->assertSame('content_policy_violation', $freshGen->error_code);
        $this->assertSame('Prompt violates safety policy [blocked].', $freshGen->error_message);
        $this->assertSame(0, $freshGen->reserved_user_cost_nano);
        $this->assertSame(0, $freshGen->actual_user_cost_nano);
        $this->assertSame(0, $freshGen->actual_provider_cost_nano);
        $this->assertNotNull($freshGen->settled_at);
        $this->assertNotNull($freshGen->completed_at);

        $this->assertSame(1_000_000_000, $freshWallet->balance_nanodollars);
        $this->assertSame(0, $freshWallet->reserved_nanodollars);

        $this->assertDatabaseCount('wallet_transactions', 2); // Hold + Release
        $releaseEntry = WalletTransaction::query()->latest('id')->firstOrFail();
        $this->assertSame(WalletTransactionType::Release, $releaseEntry->type);
    }

    public function test_initiate_handles_daily_limit_and_validation_failed_by_failing_and_refunding_hold(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::sequence()
                ->push([
                    'error' => [
                        'type' => 'rate_limit_error',
                        'code' => 'daily_limit_exceeded',
                        'message' => 'Daily limit reached.',
                    ],
                ], 429)
                ->push([
                    'error' => [
                        'type' => 'invalid_request_error',
                        'code' => 'validation_failed',
                        'message' => 'Reference image URL is invalid.',
                    ],
                ], 422),
        ]);

        $first = $this->service->initiate(
            user: $user,
            prompt: 'Daily limit test',
            size: ImageSize::Square,
            quality: ImageQuality::Low,
        );

        $this->assertSame(GenerationStatus::Failed, $first->status);
        $this->assertSame('daily_limit_exceeded', $first->error_code);
        $this->assertSame(0, $first->actual_user_cost_nano);
        $this->assertSame(1_000_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);

        $second = $this->service->initiate(
            user: $user,
            prompt: 'Validation failed test',
            size: ImageSize::Square,
            quality: ImageQuality::Low,
        );

        $this->assertSame(GenerationStatus::Failed, $second->status);
        $this->assertSame('validation_failed', $second->error_code);
        $this->assertSame(0, $second->actual_user_cost_nano);
        $this->assertSame(1_000_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);
    }

    public function test_rate_limit_and_persistent_503_overloaded_fail_and_release_hold(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::sequence()
                ->push([
                    'error' => [
                        'type' => 'rate_limit_error',
                        'code' => 'rate_limit_exceeded',
                        'message' => 'Rate limit exceeded.',
                    ],
                ], 429)
                ->push([
                    'error' => [
                        'type' => 'api_error',
                        'code' => 'overloaded',
                        'message' => 'Still overloaded.',
                    ],
                ], 503)
                ->push([
                    'error' => [
                        'type' => 'api_error',
                        'code' => 'overloaded',
                        'message' => 'Still overloaded.',
                    ],
                ], 503),
        ]);

        $rateLimited = $this->service->initiate(
            user: $user,
            prompt: 'Rate limited',
            size: ImageSize::Square,
            quality: ImageQuality::Low,
        );

        $this->assertSame(GenerationStatus::Failed, $rateLimited->status);
        $this->assertNull($rateLimited->pixelforge_id);
        $this->assertSame('rate_limit_exceeded', $rateLimited->error_code);
        $this->assertSame(1_000_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);

        $overloaded = $this->service->initiate(
            user: $user,
            prompt: 'Overloaded',
            size: ImageSize::Square,
            quality: ImageQuality::Low,
        );

        $this->assertSame(GenerationStatus::Failed, $overloaded->status);
        $this->assertNull($overloaded->pixelforge_id);
        $this->assertSame('overloaded', $overloaded->error_code);
        $this->assertSame(1_000_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);

        // 1 attempt for 429 + 2 inline Client attempts for 503 overloaded
        Http::assertSentCount(3);
    }

    public function test_apply_provider_payload_protects_against_early_webhook_race_condition(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($user)->create([
            'status' => GenerationStatus::Queued,
            'pixelforge_id' => null,
            'locked_rates' => self::SAMPLE_RATES,
            'estimated_max_user_cost_nano' => 72_187_500,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 72_187_500);

        // Early webhook arrives BEFORE POST /v1/generations returns its 202 response
        $this->service->updateFromProvider($generation, [
            'status' => 'succeeded',
            'image_url' => 'https://cdn.pixelforge.test/final.png',
            'image_url_expires_at' => '2026-11-22T12:00:00Z',
            'completed_at' => '2026-11-21T12:05:00Z',
            'usage' => [
                'input_text_tokens' => 18,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 162,
            ],
        ]);

        $settled = $generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $settled->status);
        $this->assertSame(46_115_000, $settled->actual_provider_cost_nano);
        $this->assertSame(57_643_750, $settled->actual_user_cost_nano);
        $this->assertNotNull($settled->settled_at);
        $this->assertSame(1_000_000_000 - 57_643_750, $user->fresh()->wallet->balance_nanodollars);

        // Delayed 202 response from POST /v1/generations arrives afterward with status: 'queued'
        $afterLate202 = $this->service->updateFromProvider($generation, [
            'id' => 'gen_late_202',
            'status' => 'queued',
            'rates' => [
                'input_text_tokens' => '99.00',
                'input_image_tokens' => '99.00',
                'output_tokens' => '99.00',
                'reasoning_tokens' => '99.00',
            ],
        ]);

        $this->assertSame('gen_late_202', $afterLate202->pixelforge_id);
        $this->assertSame(GenerationStatus::Succeeded, $afterLate202->status);
        $this->assertSame(46_115_000, $afterLate202->actual_provider_cost_nano);
        $this->assertSame(57_643_750, $afterLate202->actual_user_cost_nano);
        $this->assertSame('https://cdn.pixelforge.test/final.png', $afterLate202->image_url);
        $this->assertSame(self::SAMPLE_RATES, $afterLate202->locked_rates);
        $this->assertDatabaseCount('wallet_transactions', 2); // Hold + Charge only
    }

    public function test_immediate_succeeded_response_settles_billing_once(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_replayed_done',
                'status' => 'succeeded',
                'image_url' => 'https://cdn.pixelforge.test/replayed.png',
                'completed_at' => '2026-11-21T12:00:00Z',
                'usage' => [
                    'input_text_tokens' => 18,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 162,
                ],
            ], 202, ['Idempotent-Replayed' => 'true']),
        ]);

        $settled = $this->service->initiate(
            user: $user,
            prompt: 'Immediate succeeded test',
            size: ImageSize::Square,
            quality: ImageQuality::Medium,
        );

        $this->assertSame(GenerationStatus::Succeeded, $settled->status);
        $this->assertSame('gen_replayed_done', $settled->pixelforge_id);
        $this->assertSame(57_643_750, $settled->actual_user_cost_nano);
        $this->assertSame(1_000_000_000 - 57_643_750, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);
        $this->assertDatabaseCount('wallet_transactions', 2);
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
