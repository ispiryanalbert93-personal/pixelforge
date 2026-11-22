<?php

namespace Tests\Feature;

use App\Actions\Wallet\HoldWalletFunds;
use App\Actions\Wallet\TopUpWallet;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\PricingCalculator;
use App\Services\Pixelforge\PricingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GenerationControllerTest extends TestCase
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

        Cache::put(PricingService::CACHE_KEY, $this->samplePricingConfig(), 3600);

        $this->app->instance(Client::class, new Client(retrySleepMs: 0));
    }

    public function test_guests_are_redirected_to_login_from_dashboard_and_generation_submission(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');

        $this->post('/generations', [
            'prompt' => 'A neon skyline',
            'size' => ImageSize::Square->value,
            'quality' => ImageQuality::Medium->value,
        ])->assertRedirect('/login');
    }

    public function test_dashboard_renders_blank_studio_with_pricing_config_and_shared_wallet_props(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->withBalance(5_000_000_000)->create();

        $topUpWallet = app(TopUpWallet::class);
        $topUpWallet->handle($user, 12_500_000_000, 'Initial deposit');

        $olderQueued = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_older_queued',
            'status' => GenerationStatus::Queued,
            'prompt' => 'Older queued prompt',
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Low,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subSeconds(20),
        ]);

        app(HoldWalletFunds::class)->handle($user, $olderQueued, 50_000_000);

        Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_newer_succeeded',
            'status' => GenerationStatus::Succeeded,
            'prompt' => 'Newer succeeded prompt',
            'size' => ImageSize::Landscape,
            'quality' => ImageQuality::High,
            'image_url' => 'https://cdn.pixelforge.test/newer.png',
            'settled_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        Generation::factory()->for($otherUser)->create([
            'pixelforge_id' => 'gen_other_user',
            'status' => GenerationStatus::Succeeded,
            'prompt' => 'Should not leak',
        ]);

        Http::fake();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->missing('generations')
            ->missing('wallet')
            ->missing('has_active_generations')
            ->missing('auth.user.is_admin')
            ->missing('auth.ledger_entries')
            ->where('flash.current_generation', null)
            ->where('auth.user.balance_nanodollars', 12_450_000_000)
            ->where('auth.user.balance_usd', '12.450000000')
            ->where('auth.user.balance_formatted', '$12.45')
            ->where('auth.user.reserved_nanodollars', 50_000_000)
            ->where('auth.user.reserved_usd', '0.050000000')
            ->where('auth.user.reserved_formatted', '$0.05')
            ->where('pricing_config.rates', self::SAMPLE_RATES)
            ->where('pricing_config.tokens_per_reference_image', 765)
            ->where('pricing_config.max_reference_images', PricingCalculator::MAX_REFERENCE_IMAGES)
            ->where('pricing_config.markup_percent', Money::DEFAULT_MARKUP_PERCENT)
            ->has('pricing_config.sizes', 3)
            ->has('pricing_config.qualities', 3)
        );

        Http::assertNothingSent();
    }

    public function test_store_generation_validates_inputs(): void
    {
        $user = User::factory()->withBalance(10_000_000_000)->create();

        Http::fake();

        $this->actingAs($user)
            ->from('/dashboard')
            ->post('/generations', [
                'prompt' => '',
                'size' => 'invalid-size',
                'quality' => 'ultra',
                'reference_images' => [
                    'ftp://example.com/bad-scheme.png',
                    'not-a-url',
                    'https://example.com/3.png',
                    'https://example.com/4.png',
                    'https://example.com/5.png',
                ],
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors([
                'prompt',
                'size',
                'quality',
                'reference_images',
                'reference_images.0',
                'reference_images.1',
            ]);

        $this->actingAs($user)
            ->from('/dashboard')
            ->post('/generations', [
                'prompt' => str_repeat('a', 4001),
                'size' => ImageSize::Square->value,
                'quality' => ImageQuality::Low->value,
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['prompt']);

        Http::assertNothingSent();
    }

    public function test_store_generation_filters_blank_reference_images_before_validation(): void
    {
        $user = User::factory()->withBalance(5_000_000_000)->create();

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_filtered_refs',
                'status' => 'queued',
                'rates' => self::SAMPLE_RATES,
            ], 202),
        ]);

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->post('/generations', [
                'prompt' => 'A tranquil mountain lake at sunrise',
                'size' => ImageSize::Square->value,
                'quality' => ImageQuality::Medium->value,
                'reference_images' => [
                    '',
                    '   ',
                    'https://example.com/ref-1.png',
                    null,
                ],
            ]);

        $response->assertRedirect('/dashboard');
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $generation = Generation::query()->firstOrFail();
        $this->assertSame(['https://example.com/ref-1.png'], $generation->reference_images);
    }

    public function test_store_generation_rejects_insufficient_credits_without_calling_pixelforge(): void
    {
        $user = User::factory()->withBalance(10_000_000)->create(); // $0.01

        Http::fake();

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->post('/generations', [
                'prompt' => 'High-detail cyberpunk metropolis',
                'size' => ImageSize::Landscape->value,
                'quality' => ImageQuality::High->value,
            ]);

        $response->assertRedirect('/dashboard');
        $response->assertSessionHasErrors(['credits']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('generations', 0);
        $this->assertSame(10_000_000, $user->fresh()->wallet->balance_nanodollars);
    }

    public function test_store_generation_initiates_generation_places_hold_and_redirects_with_flash_success(): void
    {
        $user = User::factory()->withBalance(10_000_000_000)->create();
        $prompt = 'Studio portrait of an astronaut holding a lantern';

        $estimate = (new PricingCalculator)->estimate(
            prompt: $prompt,
            size: ImageSize::Portrait,
            quality: ImageQuality::Medium,
            referenceImageCount: 1,
            pricingConfig: $this->samplePricingConfig(),
        );

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'id' => 'gen_web_created',
                'status' => 'queued',
                'rates' => self::SAMPLE_RATES,
            ], 202),
        ]);

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->post('/generations', [
                'prompt' => $prompt,
                'size' => ImageSize::Portrait->value,
                'quality' => ImageQuality::Medium->value,
                'reference_images' => ['https://example.com/lantern.png'],
            ]);

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('success');

        $generation = Generation::query()->firstOrFail();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame('gen_web_created', $generation->pixelforge_id);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame($estimate->maxUserCostNano, $generation->reserved_user_cost_nano);

        $response->assertSessionHas('current_generation', fn (array $payload): bool => $payload['id'] === $generation->id
            && $payload['pixelforge_id'] === 'gen_web_created'
            && $payload['status'] === 'queued');

        $freshWallet = $user->fresh()->wallet;
        $this->assertSame(10_000_000_000 - $estimate->maxUserCostNano, $freshWallet->balance_nanodollars);
        $this->assertSame($estimate->maxUserCostNano, $freshWallet->reserved_nanodollars);
    }

    public function test_admin_users_are_separated_from_customer_generation_routes(): void
    {
        $admin = User::factory()->admin()->withBalance(1_000_000_000)->create();

        Http::fake();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertRedirect(route('admin.reports'));

        $this->actingAs($admin)
            ->get('/generations/history')
            ->assertRedirect(route('admin.reports'));

        $this->actingAs($admin)
            ->post('/generations', [
                'prompt' => 'Admin should not generate',
                'size' => ImageSize::Square->value,
                'quality' => ImageQuality::Medium->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('generations', 0);
        Http::assertNothingSent();
    }

    public function test_dashboard_exposes_is_development_flag_based_on_environment(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        Http::fake();

        $this->app['env'] = 'local';
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('is_development', true)
            );

        $this->app['env'] = 'development';
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('is_development', true)
            );

        $this->app['env'] = 'production';
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('is_development', false)
            );
    }

    public function test_dashboard_exposes_pricing_error_without_fallback_when_pricing_api_fails(): void
    {
        Cache::flush();
        $user = User::factory()->withBalance(1_000_000_000)->create();

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
                'error' => ['code' => 'overloaded', 'message' => 'Pricing service busy'],
            ], 503),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('pricing_config', null)
                ->where('pricing_error', 'Pixelforge API error (503 overloaded): Pricing service busy')
            );
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
