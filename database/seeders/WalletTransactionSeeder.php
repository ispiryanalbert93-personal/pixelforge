<?php

namespace Database\Seeders;

use App\Actions\Wallet\HoldWalletFunds;
use App\Actions\Wallet\ReleaseWalletHold;
use App\Actions\Wallet\SettleWalletCharge;
use App\Actions\Wallet\TopUpWallet;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WalletTransactionSeeder extends Seeder
{
    /**
     * Seed sample wallet transactions across all transaction types.
     */
    public function run(): void
    {
        $topUpWallet = app(TopUpWallet::class);
        $holdWalletFunds = app(HoldWalletFunds::class);
        $settleWalletCharge = app(SettleWalletCharge::class);
        $releaseWalletHold = app(ReleaseWalletHold::class);

        $customers = User::query()
            ->where('is_admin', false)
            ->get();

        if ($customers->isEmpty()) {
            $customers = collect([
                User::factory()->create([
                    'name' => 'Demo User',
                    'email' => 'test@example.com',
                ]),
            ]);
        }

        foreach ($customers as $user) {
            $topUpWallet->handle(
                user: $user,
                amountNano: Money::usdToNano('15.00'),
                description: 'Seeded wallet top-up ($15.00)',
            );

            $succeededGeneration = Generation::query()->create([
                'user_id' => $user->id,
                'pixelforge_id' => 'gen_seed_'.Str::lower(Str::random(10)),
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::Succeeded,
                'prompt' => 'Minimalist architectural courtyard in warm morning light',
                'size' => ImageSize::Square,
                'quality' => ImageQuality::Medium,
                'reference_images' => [],
                'estimated_min_user_cost_nano' => 58_125_000,
                'estimated_user_cost_nano' => 72_187_500,
                'estimated_max_user_cost_nano' => 86_250_000,
                'completed_at' => now()->subMinutes(10),
            ]);

            $holdWalletFunds->handle($user, $succeededGeneration, 86_250_000);
            $settleWalletCharge->handle($succeededGeneration, 55_000_000, 68_750_000);

            $failedGeneration = Generation::query()->create([
                'user_id' => $user->id,
                'pixelforge_id' => 'gen_seed_'.Str::lower(Str::random(10)),
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::Failed,
                'prompt' => 'Sample failed generation for wallet hold release',
                'size' => ImageSize::Portrait,
                'quality' => ImageQuality::Low,
                'reference_images' => [],
                'estimated_min_user_cost_nano' => 31_250_000,
                'estimated_user_cost_nano' => 37_500_000,
                'estimated_max_user_cost_nano' => 45_000_000,
                'error_code' => 'generation_failed',
                'error_message' => 'Seeded failure example.',
                'completed_at' => now()->subMinutes(5),
            ]);

            $holdWalletFunds->handle($user, $failedGeneration, 45_000_000);
            $releaseWalletHold->handle($failedGeneration);

            $activeGeneration = Generation::query()->create([
                'user_id' => $user->id,
                'pixelforge_id' => 'gen_seed_'.Str::lower(Str::random(10)),
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::Processing,
                'prompt' => 'Vibrant neon cityscape reflected in rain puddles',
                'size' => ImageSize::Landscape,
                'quality' => ImageQuality::High,
                'reference_images' => [],
                'estimated_min_user_cost_nano' => 225_000_000,
                'estimated_user_cost_nano' => 300_000_000,
                'estimated_max_user_cost_nano' => 375_000_000,
            ]);

            $holdWalletFunds->handle($user, $activeGeneration, 375_000_000);
        }
    }
}
