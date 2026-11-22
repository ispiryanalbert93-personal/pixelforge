<?php

namespace App\Actions\Wallet;

use App\Enums\WalletTransactionType;
use App\Models\Generation;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class FinalizeWalletSettlement
{
    public function __construct(
        private readonly LockUserWallet $lockUserWallet,
    ) {}

    /**
     * Atomically finalize a generation hold as either a charge or a full release.
     *
     * Negative-balance policy: if provider rates rise mid-flight after a hold was placed or a timed-out
     * generation recovers via webhook after its hold was released, actual cost is still charged in full
     * (even if balance dips below zero) so customer billing and /v1/usage reconciliation remain exact.
     */
    public function handle(
        Generation $generation,
        WalletTransactionType $type,
        int|float|string $actualProviderCostNano,
        int|float|string $actualUserCostNano,
        string $description,
    ): ?WalletTransaction {
        $providerCost = Money::normalizeNano($actualProviderCostNano);
        $userCost = Money::normalizeNano($actualUserCostNano);

        $transaction = DB::transaction(function () use (
            $generation,
            $type,
            $providerCost,
            $userCost,
            $description,
        ): ?WalletTransaction {
            $lockedWallet = $this->lockUserWallet->handle((int) $generation->user_id);

            $lockedGeneration = Generation::whereKey($generation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedGeneration->settled_at !== null) {
                return null;
            }

            $reserved = Money::normalizeNano($lockedGeneration->reserved_user_cost_nano ?? 0);
            $refundNano = Money::sub($reserved, $userCost);

            $lockedWallet->balance_nanodollars = Money::add($lockedWallet->balance_nanodollars ?? 0, $refundNano);
            $lockedWallet->reserved_nanodollars = Money::maxZero(
                Money::sub($lockedWallet->reserved_nanodollars ?? 0, $reserved),
            );
            $lockedWallet->save();

            $lockedGeneration->reserved_user_cost_nano = 0;
            $lockedGeneration->actual_provider_cost_nano = Money::toIntOrString($providerCost);
            $lockedGeneration->actual_user_cost_nano = Money::toIntOrString($userCost);
            $lockedGeneration->settled_at = now();
            $lockedGeneration->save();

            return WalletTransaction::create([
                'user_id' => $lockedWallet->user_id,
                'wallet_id' => $lockedWallet->id,
                'generation_id' => $lockedGeneration->id,
                'type' => $type,
                'amount_nanodollars' => $refundNano,
                'balance_after_nanodollars' => $lockedWallet->balance_nanodollars,
                'reserved_after_nanodollars' => $lockedWallet->reserved_nanodollars,
                'description' => $description,
            ]);
        });

        $generation->refresh();
        if ($generation->relationLoaded('user')) {
            $generation->user->refresh();
        }

        return $transaction;
    }
}
