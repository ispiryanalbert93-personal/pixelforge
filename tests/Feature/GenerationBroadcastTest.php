<?php

namespace Tests\Feature;

use App\Actions\Wallet\HoldWalletFunds;
use App\Actions\Wallet\TopUpWallet;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Events\GenerationUpdated;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\GenerationService;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private GenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');
        Config::set('services.pixelforge.api_key', 'pf_test_secret_key');
        Config::set('services.pixelforge.webhook_secret', 'whsec_test_secret');

        $this->app->instance(Client::class, new Client(retrySleepMs: 0));
        $this->service = $this->app->make(GenerationService::class);
    }

    public function test_generation_updated_event_implements_should_broadcast_now_with_expected_channel_name_and_payload(): void
    {
        $user = User::factory()->create();
        app(TopUpWallet::class)->handle($user, 1_000_000_000, 'Initial credit');

        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_broadcast_payload',
            'status' => GenerationStatus::Processing,
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 50_000_000);

        $event = new GenerationUpdated($generation->fresh());

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertSame('generation.updated', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-App.Models.User.{$user->id}", $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame($generation->id, $payload['generation']['id']);
        $this->assertSame('processing', $payload['generation']['status']);
        $this->assertSame(950_000_000, $payload['wallet']['balance_nanodollars']);
        $this->assertSame('0.950000000', $payload['wallet']['balance_usd']);
        $this->assertSame('$0.95', $payload['wallet']['balance_formatted']);
        $this->assertSame(50_000_000, $payload['wallet']['reserved_nanodollars']);
        $this->assertArrayNotHasKey('ledger_entries', $payload['wallet']);
        $this->assertTrue($payload['has_active_generations']);

        Config::set('broadcasting.default', 'pusher');
        Config::set('broadcasting.connections.pusher.key', '');
        $this->assertFalse($event->broadcastWhen());

        Config::set('broadcasting.connections.pusher.key', 'test_pusher_key');
        $this->assertTrue($event->broadcastWhen());
    }

    public function test_apply_provider_payload_dispatches_generation_updated_on_transitions(): void
    {
        Event::fake([GenerationUpdated::class]);

        $user = User::factory()->withBalance(1_000_000_000)->create();

        $queuedGen = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_transition_1',
            'status' => GenerationStatus::Queued,
            'locked_rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ]);

        app(HoldWalletFunds::class)->handle($user, $queuedGen, 72_000_000);

        $this->service->updateFromProvider($queuedGen, [
            'id' => 'gen_transition_1',
            'status' => 'processing',
        ]);

        $this->service->updateFromProvider($queuedGen, [
            'id' => 'gen_transition_1',
            'status' => 'succeeded',
            'image_url' => 'https://cdn.pixelforge.test/done.png',
            'completed_at' => '2026-11-21T12:00:00Z',
            'usage' => [
                'input_text_tokens' => 18,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 162,
            ],
        ]);

        $failedGen = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_transition_fail',
            'status' => GenerationStatus::Queued,
        ]);

        app(HoldWalletFunds::class)->handle($user, $failedGen, 50_000_000);

        $this->service->updateFromProvider($failedGen, [
            'id' => 'gen_transition_fail',
            'status' => 'failed',
            'error' => [
                'code' => 'generation_failed',
                'message' => 'Upstream render failed.',
            ],
        ]);

        Event::assertDispatched(GenerationUpdated::class, 3);
        Event::assertDispatched(
            GenerationUpdated::class,
            fn (GenerationUpdated $event): bool => $event->generation->id === $queuedGen->id
                && $event->generation->status === GenerationStatus::Succeeded,
        );
        Event::assertDispatched(
            GenerationUpdated::class,
            fn (GenerationUpdated $event): bool => $event->generation->id === $failedGen->id
                && $event->generation->status === GenerationStatus::Failed,
        );
    }

    public function test_initiate_synchronous_rejection_dispatches_generation_updated_via_fail_and_release_hold(): void
    {
        Event::fake([GenerationUpdated::class]);

        $user = User::factory()->withBalance(1_000_000_000)->create();

        Http::fake([
            'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
                'rates' => [
                    'input_text_tokens' => '5.00',
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
            ], 200),
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'Blocked prompt.',
                ],
            ], 400),
        ]);

        $generation = $this->service->initiate(
            user: $user,
            prompt: 'Blocked prompt',
            size: ImageSize::Square,
            quality: ImageQuality::Medium,
        );

        $this->assertSame(GenerationStatus::Failed, $generation->status);

        Event::assertDispatchedTimes(GenerationUpdated::class, 1);
        Event::assertDispatched(
            GenerationUpdated::class,
            fn (GenerationUpdated $event): bool => $event->generation->id === $generation->id
                && $event->generation->status === GenerationStatus::Failed
                && $event->generation->error_code === 'content_policy_violation',
        );
    }

    public function test_broadcasting_auth_authorizes_matching_user_and_denies_other_user(): void
    {
        Config::set('broadcasting.default', 'pusher');
        Config::set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => 'test_key',
            'secret' => 'test_secret',
            'app_id' => '123456',
            'options' => [
                'cluster' => 'mt1',
                'useTLS' => true,
            ],
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($owner)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-App.Models.User.{$owner->id}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($otherUser)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-App.Models.User.{$owner->id}",
            ])
            ->assertForbidden();
    }

    public function test_webhook_still_returns_200_and_settles_billing_when_broadcasting_throws(): void
    {
        $attemptedBroadcast = false;

        Broadcast::extend('failing', static function () use (&$attemptedBroadcast) {
            return new class($attemptedBroadcast) extends Broadcaster
            {
                public function __construct(private bool &$attemptedBroadcast) {}

                public function auth($request)
                {
                    return null;
                }

                public function validAuthenticationResponse($request, $result)
                {
                    return null;
                }

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    $this->attemptedBroadcast = true;

                    throw new BroadcastException('Simulated Pusher outage');
                }
            };
        });

        Config::set('broadcasting.default', 'failing');
        Config::set('broadcasting.connections.failing', ['driver' => 'failing']);
        Broadcast::forgetDrivers();

        $user = User::factory()->withBalance(1_000_000_000)->create();

        $generation = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_webhook_broadcast_fail',
            'status' => GenerationStatus::Processing,
            'locked_rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ]);

        app(HoldWalletFunds::class)->handle($user, $generation, 72_187_500);

        $body = [
            'id' => 'evt_broadcast_fail_1',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'data' => [
                'object' => [
                    'id' => 'gen_webhook_broadcast_fail',
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'image_url' => 'https://cdn.pixelforge.test/still-settled.png',
                    'completed_at' => '2026-11-21T12:10:00Z',
                    'usage' => [
                        'input_text_tokens' => 18,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 162,
                    ],
                ],
            ],
        ];

        $rawBody = json_encode($body, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", 'whsec_test_secret');

        $response = $this->call(
            'POST',
            '/api/webhooks/pixelforge',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'HTTP_PIXELFORGE_EVENT_ID' => 'evt_broadcast_fail_1',
                'HTTP_PIXELFORGE_EVENT_TYPE' => 'generation.succeeded',
            ],
            $rawBody,
        );

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertTrue($attemptedBroadcast);

        $freshGeneration = $generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $freshGeneration->status);
        $this->assertSame(57_643_750, $freshGeneration->actual_user_cost_nano);
        $this->assertNotNull($freshGeneration->settled_at);
        $this->assertSame(1_000_000_000 - 57_643_750, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);
    }
}
