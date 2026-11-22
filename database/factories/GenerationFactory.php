<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pixelforge_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Queued,
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'locked_rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'usage' => null,
            'estimated_min_user_cost_nano' => 58_125_000,
            'estimated_user_cost_nano' => 72_187_500,
            'estimated_max_user_cost_nano' => 86_250_000,
            'reserved_user_cost_nano' => 0,
            'actual_provider_cost_nano' => null,
            'actual_user_cost_nano' => null,
            'settled_at' => null,
            'image_url' => null,
            'image_url_expires_at' => null,
            'error_code' => null,
            'error_message' => null,
            'completed_at' => null,
        ];
    }
}
