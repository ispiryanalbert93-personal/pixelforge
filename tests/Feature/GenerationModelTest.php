<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookEvent;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_factory_generates_integer_id_and_casts_attributes_correctly(): void
    {
        $expiresAt = now()->addHour();
        $completedAt = now();
        $settledAt = now();

        $generation = Generation::factory()->create([
            'status' => GenerationStatus::Queued,
            'size' => ImageSize::Landscape,
            'quality' => ImageQuality::High,
            'reference_images' => ['https://example.com/ref-1.png'],
            'locked_rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'usage' => [
                'input_text_tokens' => 20,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 4500,
            ],
            'image_url_expires_at' => $expiresAt,
            'completed_at' => $completedAt,
            'settled_at' => $settledAt,
        ]);

        $fresh = $generation->fresh();

        $this->assertNotNull($fresh);
        $this->assertIsInt($fresh->id);
        $this->assertGreaterThan(0, $fresh->id);
        $this->assertInstanceOf(User::class, $fresh->user);
        $this->assertSame(GenerationStatus::Queued, $fresh->status);
        $this->assertSame(ImageSize::Landscape, $fresh->size);
        $this->assertSame(ImageQuality::High, $fresh->quality);
        $this->assertIsArray($fresh->reference_images);
        $this->assertSame(['https://example.com/ref-1.png'], $fresh->reference_images);
        $this->assertIsArray($fresh->locked_rates);
        $this->assertSame('40.00', $fresh->locked_rates['output_tokens']);
        $this->assertIsArray($fresh->usage);
        $this->assertSame(6450, $fresh->usage['output_tokens']);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->image_url_expires_at);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->completed_at);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->settled_at);
    }

    public function test_generation_status_transitions_are_monotonic(): void
    {
        $queuedToProcessing = Generation::factory()->create(['status' => GenerationStatus::Queued]);
        $this->assertTrue($queuedToProcessing->transitionTo(GenerationStatus::Processing));
        $this->assertSame(GenerationStatus::Processing, $queuedToProcessing->fresh()->status);

        // Cannot regress from processing back to queued
        $this->assertFalse($queuedToProcessing->transitionTo(GenerationStatus::Queued));
        $this->assertSame(GenerationStatus::Processing, $queuedToProcessing->fresh()->status);

        // Can advance from processing to succeeded
        $this->assertTrue($queuedToProcessing->transitionTo(GenerationStatus::Succeeded));
        $this->assertSame(GenerationStatus::Succeeded, $queuedToProcessing->fresh()->status);

        // Terminal succeeded cannot transition anywhere
        $this->assertFalse($queuedToProcessing->transitionTo(GenerationStatus::Failed));
        $this->assertFalse($queuedToProcessing->transitionTo(GenerationStatus::Processing));
        $this->assertSame(GenerationStatus::Succeeded, $queuedToProcessing->fresh()->status);

        // Queued can jump directly to succeeded
        $queuedToSucceeded = Generation::factory()->create(['status' => GenerationStatus::Queued]);
        $this->assertTrue($queuedToSucceeded->transitionTo(GenerationStatus::Succeeded));
        $this->assertSame(GenerationStatus::Succeeded, $queuedToSucceeded->fresh()->status);

        // Queued can jump directly to failed, and failed is terminal
        $queuedToFailed = Generation::factory()->create(['status' => GenerationStatus::Queued]);
        $this->assertTrue($queuedToFailed->transitionTo(GenerationStatus::Failed));
        $this->assertSame(GenerationStatus::Failed, $queuedToFailed->fresh()->status);
        $this->assertFalse($queuedToFailed->transitionTo(GenerationStatus::Succeeded));
        $this->assertFalse($queuedToFailed->transitionTo(GenerationStatus::Queued));
        $this->assertSame(GenerationStatus::Failed, $queuedToFailed->fresh()->status);
    }

    public function test_generation_formats_money_helpers_accurately(): void
    {
        $unsettled = Generation::factory()->create([
            'estimated_user_cost_nano' => 72_187_500,
            'actual_user_cost_nano' => null,
            'actual_provider_cost_nano' => null,
        ]);

        $this->assertSame('0.072187500', $unsettled->estimated_user_cost_usd);
        $this->assertNull($unsettled->actual_user_cost_usd);
        $this->assertNull($unsettled->actual_provider_cost_usd);

        $settled = Generation::factory()->create([
            'estimated_user_cost_nano' => 72_000_000,
            'actual_provider_cost_nano' => 46_115_000,
            'actual_user_cost_nano' => 57_643_750,
        ]);

        $this->assertSame('0.072000000', $settled->estimated_user_cost_usd);
        $this->assertSame('0.057643750', $settled->actual_user_cost_usd);
        $this->assertSame('0.046115000', $settled->actual_provider_cost_usd);
    }

    public function test_user_and_webhook_event_casts_and_relationships_work(): void
    {
        $admin = User::factory()->admin()->withBalance(2_500_000_000)->create();
        $generation = Generation::factory()->for($admin)->create();

        $this->assertTrue($admin->is_admin);
        $this->assertArrayNotHasKey('is_admin', $admin->toArray());

        $regularUser = User::factory()->create();
        $this->assertFalse($regularUser->is_admin);
        $this->assertArrayNotHasKey('is_admin', $regularUser->toArray());

        $this->assertSame('2.500000000', $admin->wallet->balance_usd);
        $this->assertSame('0.000000000', $admin->wallet->reserved_usd);
        $this->assertCount(1, $admin->generations);
        $this->assertTrue($admin->generations->first()->is($generation));
        $this->assertCount(0, $generation->walletTransactions);

        $event = WebhookEvent::query()->create([
            'event_id' => 'evt_123',
            'event_type' => 'generation.completed',
            'pixelforge_generation_id' => 'pf_gen_123',
            'payload' => ['status' => 'succeeded'],
            'processed_at' => now(),
        ]);

        $freshEvent = $event->fresh();
        $this->assertIsArray($freshEvent->payload);
        $this->assertSame('succeeded', $freshEvent->payload['status']);
        $this->assertInstanceOf(CarbonInterface::class, $freshEvent->processed_at);
    }

    public function test_user_and_wallet_mass_assignment_ignores_admin_and_balance_attributes(): void
    {
        $user = new User;
        $user->fill([
            'name' => 'Regular Person',
            'email' => 'safe@example.com',
            'password' => 'password',
            'is_admin' => true,
        ]);
        $user->save();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->is_admin);
        $this->assertSame(0, $fresh->wallet->balance_nanodollars);
        $this->assertSame(0, $fresh->wallet->reserved_nanodollars);

        $wallet = $fresh->wallet;
        $wallet->fill([
            'balance_nanodollars' => 999_000_000_000,
            'reserved_nanodollars' => 500_000_000,
        ]);
        $wallet->save();

        $freshWallet = Wallet::query()->where('user_id', $fresh->id)->firstOrFail();
        $this->assertSame(0, $freshWallet->balance_nanodollars);
        $this->assertSame(0, $freshWallet->reserved_nanodollars);
    }
}
