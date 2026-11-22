<?php

namespace App\Actions\Generations;

use App\Actions\Wallet\HoldWalletFunds;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\DTO\PricingEstimate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateGeneration
{
    public function __construct(
        private readonly HoldWalletFunds $holdWalletFunds,
    ) {}

    /**
     * Persist a queued generation and reserve the maximum user cost inside one transaction.
     *
     * @param  array<int, string>  $referenceImages
     *
     * @throws InsufficientCreditsException
     */
    public function handle(
        User $user,
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        array $referenceImages,
        PricingEstimate $estimate,
    ): Generation {
        return DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate): Generation {
            $generation = Generation::create([
                'user_id' => $user->id,
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::Queued,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'locked_rates' => $estimate->rates,
                'estimated_min_user_cost_nano' => $estimate->minUserCostNano,
                'estimated_user_cost_nano' => $estimate->expectedUserCostNano,
                'estimated_max_user_cost_nano' => $estimate->maxUserCostNano,
            ]);

            $this->holdWalletFunds->handle($user, $generation, $estimate->maxUserCostNano);

            return $generation;
        });
    }
}
