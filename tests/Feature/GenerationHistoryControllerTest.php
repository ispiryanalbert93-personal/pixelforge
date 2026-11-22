<?php

namespace Tests\Feature;

use App\Actions\Wallet\HoldWalletFunds;
use App\Actions\Wallet\TopUpWallet;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GenerationHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_generation_history(): void
    {
        $this->get('/generations/history')->assertRedirect('/login');
    }

    public function test_generation_history_renders_paginated_datatable_props_and_filter_options(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->withBalance(5_000_000_000)->create();

        app(TopUpWallet::class)->handle($user, 8_000_000_000, 'Initial deposit');

        $olderQueued = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_history_queued',
            'status' => GenerationStatus::Queued,
            'prompt' => 'History queued prompt',
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Low,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subSeconds(2),
        ]);

        app(HoldWalletFunds::class)->handle($user, $olderQueued, 40_000_000);

        $newerSucceeded = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_history_succeeded',
            'status' => GenerationStatus::Succeeded,
            'prompt' => 'History succeeded prompt',
            'size' => ImageSize::Landscape,
            'quality' => ImageQuality::High,
            'usage' => [
                'input_text_tokens' => 25,
                'input_image_tokens' => 0,
                'output_tokens' => 6450,
                'reasoning_tokens' => 2400,
            ],
            'estimated_user_cost_nano' => 300_000_000,
            'actual_provider_cost_nano' => 288_125_000,
            'actual_user_cost_nano' => 360_156_250,
            'image_url' => 'https://cdn.pixelforge.test/history-newer.png',
            'settled_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        Generation::factory()->for($otherUser)->create([
            'pixelforge_id' => 'gen_history_other',
            'status' => GenerationStatus::Succeeded,
            'prompt' => 'Other user prompt',
        ]);

        Http::fake();

        $response = $this->actingAs($user)->get(route('generations.history'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Generations/History')
            ->where('has_active_generations', true)
            ->has('generations.data', 2)
            ->where('generations.meta.total', 2)
            ->where('generations.meta.current_page', 1)
            ->where('generations.meta.last_page', 1)
            ->where('generations.data.0.id', $newerSucceeded->id)
            ->where('generations.data.0.status', 'succeeded')
            ->where('generations.data.0.image_url', 'https://cdn.pixelforge.test/history-newer.png')
            ->where('generations.data.0.reference_images_count', 0)
            ->missing('generations.data.0.reference_images')
            ->missing('generations.data.0.estimated_user_cost_nano')
            ->missing('generations.data.0.actual_user_cost_nano')
            ->missing('generations.data.0.error_code')
            ->missing('generations.data.0.completed_at')
            ->where('generations.data.1.id', $olderQueued->id)
            ->where('generations.data.1.status', 'queued')
            ->missing('wallet')
            ->where('filters.status', 'all')
            ->where('filters.per_page', 10)
            ->where('statusOptions.0.value', 'all')
            ->missing('statusOptions.0.count')
            ->where('statusOptions.1.value', 'active')
            ->missing('statusOptions.1.count')
            ->where('statusOptions.2.value', 'succeeded')
            ->missing('statusOptions.2.count')
            ->where('statusOptions.3.value', 'failed')
            ->missing('statusOptions.3.count')
            ->missing('facetedFilters')
        );

        Http::assertNothingSent();
    }

    public function test_generation_history_supports_server_side_filtering_searching_and_pagination(): void
    {
        $user = User::factory()->create();

        $queued = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_filter_queued',
            'status' => GenerationStatus::Queued,
            'prompt' => 'A neon cybernetic fox',
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Low,
            'estimated_user_cost_nano' => 20_000_000,
            'created_at' => now()->subMinutes(10),
        ]);

        $succeededOlder = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_filter_ok_1',
            'status' => GenerationStatus::Succeeded,
            'prompt' => 'Minimalist ceramic vase',
            'size' => ImageSize::Landscape,
            'quality' => ImageQuality::High,
            'actual_user_cost_nano' => 150_000_000,
            'created_at' => now()->subMinutes(8),
        ]);

        $succeededNewer = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_filter_ok_2',
            'status' => GenerationStatus::Succeeded,
            'prompt' => 'Minimalist mountain cabin',
            'size' => ImageSize::Landscape,
            'quality' => ImageQuality::High,
            'actual_user_cost_nano' => 300_000_000,
            'created_at' => now()->subMinutes(6),
        ]);

        $failed = Generation::factory()->for($user)->create([
            'pixelforge_id' => 'gen_filter_failed',
            'status' => GenerationStatus::Failed,
            'prompt' => 'Blocked prompt',
            'size' => ImageSize::Portrait,
            'quality' => ImageQuality::Medium,
            'created_at' => now()->subMinutes(4),
        ]);

        Http::fake();

        // Filter by active status tab
        $this->actingAs($user)
            ->get(route('generations.history', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 1)
                ->where('generations.data.0.id', $queued->id)
                ->where('filters.status', 'active')
            );

        // Filter by status + pagination (default created_at desc puts newer on page 1, older on page 2)
        $this->actingAs($user)
            ->get(route('generations.history', [
                'status' => 'succeeded',
                'per_page' => 1,
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 1)
                ->where('generations.data.0.id', $succeededOlder->id)
                ->where('generations.meta.total', 2)
                ->where('generations.meta.current_page', 2)
                ->where('generations.meta.last_page', 2)
                ->where('generations.meta.per_page', 1)
                ->missing('statusOptions.0.count')
                ->missing('statusOptions.3.count')
            );

        // Exact pixelforge_id match (=) returns the matching record, while partial IDs do not match
        $this->actingAs($user)
            ->get(route('generations.history', [
                'status' => 'succeeded',
                'search' => 'gen_filter_ok_1',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 1)
                ->where('generations.data.0.id', $succeededOlder->id)
                ->where('generations.meta.total', 1)
                ->where('filters.search', 'gen_filter_ok_1')
            );

        $this->actingAs($user)
            ->get(route('generations.history', ['search' => 'gen_filter_ok']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 0)
                ->where('generations.meta.total', 0)
            );

        // Prompt-only text should not match since search scopes strictly to pixelforge_id
        $this->actingAs($user)
            ->get(route('generations.history', ['search' => 'Minimalist']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 0)
                ->where('generations.meta.total', 0)
            );

        $this->actingAs($user)
            ->get(route('generations.history', ['status' => 'failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 1)
                ->where('generations.data.0.id', $failed->id)
                ->where('generations.data.0.id', fn (int $id): bool => $id !== $succeededNewer->id)
            );

        Http::assertNothingSent();
    }
}
