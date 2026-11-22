<?php

namespace Tests\Feature;

use App\Actions\Wallet\HoldWalletFunds;
use App\Enums\GenerationStatus;
use App\Enums\WalletTransactionType;
use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const string WEBHOOK_SECRET = 'whsec_test_secret_123';

    private const array SAMPLE_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.pixelforge.webhook_secret', self::WEBHOOK_SECRET);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headerOverrides
     */
    private function postSignedWebhook(
        array $payload,
        string $eventId = 'evt_default_1',
        string $eventType = 'generation.succeeded',
        ?int $timestamp = null,
        ?string $signatureOverride = null,
        bool $includeSignatureHeader = true,
        int $deliveryAttempt = 1,
        array $headerOverrides = [],
    ): TestResponse {
        $rawJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = $timestamp ?? time();

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Pixelforge-Event-Id' => $eventId,
            'Pixelforge-Event-Type' => $eventType,
            'Pixelforge-Delivery-Attempt' => (string) $deliveryAttempt,
        ];

        if ($includeSignatureHeader) {
            $signature = $signatureOverride
                ?? 't='.$ts.',v1='.hash_hmac('sha256', "{$ts}.{$rawJson}", self::WEBHOOK_SECRET);
            $headers['Pixelforge-Signature'] = $signature;
        }

        $headers = array_merge($headers, $headerOverrides);

        return $this->call(
            'POST',
            '/api/webhooks/pixelforge',
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers),
            $rawJson,
        );
    }

    public function test_missing_signature_header_returns_401(): void
    {
        $response = $this->postSignedWebhook(
            payload: ['id' => 'evt_missing_sig', 'type' => 'ping'],
            eventId: 'evt_missing_sig',
            eventType: 'ping',
            includeSignatureHeader: false,
        );

        $response->assertUnauthorized();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_invalid_hmac_signature_returns_401(): void
    {
        $timestamp = time();

        $response = $this->postSignedWebhook(
            payload: ['id' => 'evt_bad_sig', 'type' => 'ping'],
            eventId: 'evt_bad_sig',
            eventType: 'ping',
            timestamp: $timestamp,
            signatureOverride: "t={$timestamp},v1=deadbeefinvalidhmac",
        );

        $response->assertUnauthorized();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_stale_and_far_future_timestamps_return_401(): void
    {
        $staleResponse = $this->postSignedWebhook(
            payload: ['id' => 'evt_stale_ts', 'type' => 'ping'],
            eventId: 'evt_stale_ts',
            eventType: 'ping',
            timestamp: time() - 301,
        );

        $staleResponse->assertUnauthorized();

        $futureResponse = $this->postSignedWebhook(
            payload: ['id' => 'evt_future_ts', 'type' => 'ping'],
            eventId: 'evt_future_ts',
            eventType: 'ping',
            timestamp: time() + 301,
        );

        $futureResponse->assertUnauthorized();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_valid_signature_within_300s_window_returns_200(): void
    {
        $response = $this->postSignedWebhook(
            payload: ['id' => 'evt_valid_window', 'type' => 'ping'],
            eventId: 'evt_valid_window',
            eventType: 'ping',
            timestamp: time() - 295,
        );

        $response->assertOk();
    }

    public function test_ping_event_returns_200_and_records_webhook_event(): void
    {
        $response = $this->postSignedWebhook(
            payload: [
                'id' => 'evt_ping_1',
                'type' => 'ping',
                'created_at' => '2026-11-21T12:00:00Z',
                'data' => ['message' => 'pong'],
            ],
            eventId: 'evt_ping_1',
            eventType: 'ping',
        );

        $response->assertOk();

        $this->assertDatabaseCount('webhook_events', 1);
        $event = WebhookEvent::query()->where('event_id', 'evt_ping_1')->firstOrFail();
        $this->assertSame('ping', $event->event_type);
        $this->assertNull($event->pixelforge_generation_id);
        $this->assertNotNull($event->processed_at);
    }

    public function test_lifecycle_progression_queued_to_processing_to_succeeded_settles_exact_billing(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_live_1',
            'status' => GenerationStatus::Queued,
            'locked_rates' => self::SAMPLE_RATES,
            'estimated_max_user_cost_nano' => 72_000_000,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 72_000_000);

        $processingResponse = $this->postSignedWebhook(
            payload: [
                'id' => 'evt_proc_1',
                'object' => 'event',
                'type' => 'generation.processing',
                'data' => [
                    'object' => [
                        'id' => 'gen_live_1',
                        'object' => 'generation',
                        'status' => 'processing',
                    ],
                ],
            ],
            eventId: 'evt_proc_1',
            eventType: 'generation.processing',
        );

        $processingResponse->assertOk();

        $afterProcessing = $generation->fresh();
        $this->assertSame(GenerationStatus::Processing, $afterProcessing->status);
        $this->assertSame(72_000_000, $afterProcessing->reserved_user_cost_nano);
        $this->assertNull($afterProcessing->settled_at);
        $this->assertSame(928_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(72_000_000, $user->fresh()->wallet->reserved_nanodollars);

        $succeededResponse = $this->postSignedWebhook(
            payload: [
                'id' => 'evt_succ_1',
                'object' => 'event',
                'type' => 'generation.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'gen_live_1',
                        'object' => 'generation',
                        'status' => 'succeeded',
                        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_live_1.png',
                        'completed_at' => '2026-11-21T12:00:00Z',
                        'usage' => [
                            'input_text_tokens' => 18,
                            'input_image_tokens' => 0,
                            'output_tokens' => 1100,
                            'reasoning_tokens' => 162,
                        ],
                    ],
                ],
            ],
            eventId: 'evt_succ_1',
            eventType: 'generation.succeeded',
        );

        $succeededResponse->assertOk();

        $settled = $generation->fresh();
        $freshWallet = $user->fresh()->wallet;

        $this->assertSame(GenerationStatus::Succeeded, $settled->status);
        $this->assertSame('https://pixelforge.promptedit.dev/images/gen_live_1.png', $settled->image_url);
        $this->assertSame([
            'input_text_tokens' => 18,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 162,
        ], $settled->usage);
        $this->assertSame('2026-11-21T12:00:00+00:00', $settled->completed_at?->toIso8601String());
        $this->assertSame(46_115_000, $settled->actual_provider_cost_nano);
        $this->assertSame(57_643_750, $settled->actual_user_cost_nano);
        $this->assertSame(0, $settled->reserved_user_cost_nano);
        $this->assertNotNull($settled->settled_at);

        // Refund unused hold (72_000_000 - 57_643_750 = 14_356_250) back to balance (928_000_000 + 14_356_250)
        $this->assertSame(942_356_250, $freshWallet->balance_nanodollars);
        $this->assertSame(0, $freshWallet->reserved_nanodollars);

        $chargeEntry = WalletTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', WalletTransactionType::Charge)
            ->firstOrFail();

        $this->assertSame(14_356_250, $chargeEntry->amount_nanodollars);
        $this->assertDatabaseCount('webhook_events', 2);
    }

    public function test_failed_generation_webhook_transitions_to_failed_and_releases_full_hold(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_fail_1',
            'status' => GenerationStatus::Processing,
            'estimated_max_user_cost_nano' => 72_000_000,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 72_000_000);

        $response = $this->postSignedWebhook(
            payload: [
                'id' => 'evt_fail_1',
                'object' => 'event',
                'type' => 'generation.failed',
                'data' => [
                    'object' => [
                        'id' => 'gen_fail_1',
                        'object' => 'generation',
                        'status' => 'failed',
                        'error' => [
                            'code' => 'generation_failed',
                            'message' => 'Internal rendering failure',
                        ],
                    ],
                ],
            ],
            eventId: 'evt_fail_1',
            eventType: 'generation.failed',
        );

        $response->assertOk();

        $failed = $generation->fresh();
        $freshWallet = $user->fresh()->wallet;

        $this->assertSame(GenerationStatus::Failed, $failed->status);
        $this->assertSame('generation_failed', $failed->error_code);
        $this->assertSame('Internal rendering failure', $failed->error_message);
        $this->assertSame(0, $failed->actual_provider_cost_nano);
        $this->assertSame(0, $failed->actual_user_cost_nano);
        $this->assertSame(0, $failed->reserved_user_cost_nano);
        $this->assertNotNull($failed->settled_at);

        $this->assertSame(1_000_000_000, $freshWallet->balance_nanodollars);
        $this->assertSame(0, $freshWallet->reserved_nanodollars);

        $releaseEntry = WalletTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', WalletTransactionType::Release)
            ->firstOrFail();

        $this->assertSame(72_000_000, $releaseEntry->amount_nanodollars);
    }

    public function test_exact_duplicate_event_id_delivered_twice_only_processes_and_charges_once(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_dup_1',
            'status' => GenerationStatus::Processing,
            'locked_rates' => self::SAMPLE_RATES,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 72_000_000);

        $payload = [
            'id' => 'evt_dup_1',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'data' => [
                'object' => [
                    'id' => 'gen_dup_1',
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'image_url' => 'https://pixelforge.promptedit.dev/images/gen_dup_1.png',
                    'completed_at' => '2026-11-21T12:00:00Z',
                    'usage' => [
                        'input_text_tokens' => 18,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 162,
                    ],
                ],
            ],
        ];

        $first = $this->postSignedWebhook($payload, eventId: 'evt_dup_1', eventType: 'generation.succeeded', deliveryAttempt: 1);
        $second = $this->postSignedWebhook($payload, eventId: 'evt_dup_1', eventType: 'generation.succeeded', deliveryAttempt: 2);

        $first->assertOk();
        $second->assertOk();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransactionType::Charge)->count());
        $this->assertSame(942_356_250, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);
    }

    public function test_distinct_event_ids_for_same_succeeded_generation_settle_billing_only_once(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_live_1',
            'status' => GenerationStatus::Processing,
            'locked_rates' => self::SAMPLE_RATES,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 72_000_000);

        $data = [
            'id' => 'gen_live_1',
            'status' => 'succeeded',
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_live_1.png',
            'completed_at' => '2026-11-21T12:00:00Z',
            'usage' => [
                'input_text_tokens' => 18,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 162,
            ],
        ];

        $first = $this->postSignedWebhook(
            payload: ['id' => 'evt_dup_a', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $data]],
            eventId: 'evt_dup_a',
            eventType: 'generation.succeeded',
        );

        $second = $this->postSignedWebhook(
            payload: ['id' => 'evt_dup_b', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $data]],
            eventId: 'evt_dup_b',
            eventType: 'generation.succeeded',
        );

        $first->assertOk();
        $second->assertOk();

        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransactionType::Charge)->count());
        $this->assertSame(942_356_250, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);
    }

    public function test_out_of_order_webhook_delivery_does_not_regress_succeeded_state_to_processing(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_ooo_1',
            'status' => GenerationStatus::Queued,
            'locked_rates' => self::SAMPLE_RATES,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 72_000_000);

        $succeededResponse = $this->postSignedWebhook(
            payload: [
                'id' => 'evt_ooo_succ',
                'object' => 'event',
                'type' => 'generation.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'gen_ooo_1',
                        'object' => 'generation',
                        'status' => 'succeeded',
                        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_ooo_1.png',
                        'completed_at' => '2026-11-21T12:00:00Z',
                        'usage' => [
                            'input_text_tokens' => 18,
                            'input_image_tokens' => 0,
                            'output_tokens' => 1100,
                            'reasoning_tokens' => 162,
                        ],
                    ],
                ],
            ],
            eventId: 'evt_ooo_succ',
            eventType: 'generation.succeeded',
        );

        $lateProcessingResponse = $this->postSignedWebhook(
            payload: [
                'id' => 'evt_ooo_proc',
                'object' => 'event',
                'type' => 'generation.processing',
                'data' => [
                    'object' => [
                        'id' => 'gen_ooo_1',
                        'object' => 'generation',
                        'status' => 'processing',
                    ],
                ],
            ],
            eventId: 'evt_ooo_proc',
            eventType: 'generation.processing',
        );

        $succeededResponse->assertOk();
        $lateProcessingResponse->assertOk();

        $fresh = $generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $fresh->status);
        $this->assertSame('https://pixelforge.promptedit.dev/images/gen_ooo_1.png', $fresh->image_url);
        $this->assertSame(46_115_000, $fresh->actual_provider_cost_nano);
        $this->assertSame(57_643_750, $fresh->actual_user_cost_nano);
        $this->assertSame(942_356_250, $user->fresh()->wallet->balance_nanodollars);
    }

    public function test_early_webhook_race_matches_via_metadata_local_generation_id_and_settles_billing(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => null,
            'status' => GenerationStatus::Queued,
            'locked_rates' => self::SAMPLE_RATES,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 72_000_000);

        $response = $this->postSignedWebhook(
            payload: [
                'id' => 'evt_early_99',
                'object' => 'event',
                'type' => 'generation.succeeded',
                'data' => [
                    'object' => [
                        'id' => 'gen_early_99',
                        'object' => 'generation',
                        'status' => 'succeeded',
                        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_early_99.png',
                        'completed_at' => '2026-11-21T12:00:00Z',
                        'usage' => [
                            'input_text_tokens' => 18,
                            'input_image_tokens' => 0,
                            'output_tokens' => 1100,
                            'reasoning_tokens' => 162,
                        ],
                        'metadata' => [
                            'local_generation_id' => (string) $generation->id,
                            'user_id' => (string) $user->id,
                        ],
                    ],
                ],
            ],
            eventId: 'evt_early_99',
            eventType: 'generation.succeeded',
        );

        $response->assertOk();

        $fresh = $generation->fresh();
        $this->assertSame('gen_early_99', $fresh->pixelforge_id);
        $this->assertSame(GenerationStatus::Succeeded, $fresh->status);
        $this->assertSame('https://pixelforge.promptedit.dev/images/gen_early_99.png', $fresh->image_url);
        $this->assertSame(46_115_000, $fresh->actual_provider_cost_nano);
        $this->assertSame(57_643_750, $fresh->actual_user_cost_nano);
        $this->assertSame(942_356_250, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);

        $event = WebhookEvent::query()->where('event_id', 'evt_early_99')->firstOrFail();
        $this->assertSame('gen_early_99', $event->pixelforge_generation_id);
        $this->assertNotNull($event->processed_at);
    }

    public function test_flat_data_payload_fallback_is_also_supported(): void
    {
        $user = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_flat_1',
            'status' => GenerationStatus::Processing,
            'locked_rates' => self::SAMPLE_RATES,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 72_000_000);

        $response = $this->postSignedWebhook(
            payload: [
                'id' => 'evt_flat_1',
                'type' => 'generation.succeeded',
                'data' => [
                    'id' => 'gen_flat_1',
                    'status' => 'succeeded',
                    'image_url' => 'https://pixelforge.promptedit.dev/images/gen_flat_1.png',
                    'completed_at' => '2026-11-21T12:00:00Z',
                    'usage' => [
                        'input_text_tokens' => 18,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 162,
                    ],
                ],
            ],
            eventId: 'evt_flat_1',
            eventType: 'generation.succeeded',
        );

        $response->assertOk();

        $fresh = $generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $fresh->status);
        $this->assertSame(57_643_750, $fresh->actual_user_cost_nano);
        $this->assertSame(942_356_250, $user->fresh()->wallet->balance_nanodollars);
    }
}
