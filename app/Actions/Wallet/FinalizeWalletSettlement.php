<?php

namespace App\Actions\Wallet;

use App\Enums\WalletTransactionType;
use App\Models\Generation;
use App\Models\WalletTransaction;
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
        int $actualProviderCostNano,
        int $actualUserCostNano,
        string $description,
    ): ?WalletTransaction {
        $transaction = DB::transaction(function () use (
            $generation,
            $type,
            $actualProviderCostNano,
            $actualUserCostNano,
            $description,
        ): ?WalletTransaction {
            $lockedWallet = $this->lockUserWallet->handle((int) $generation->user_id);

            $lockedGeneration = Generation::whereKey($generation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedGeneration->settled_at !== null) {
                return null;
            }

            $reserved = (int) $lockedGeneration->reserved_user_cost_nano;
            $refundNano = $reserved - $actualUserCostNano;

            $lockedWallet->balance_nanodollars += $refundNano;
            $lockedWallet->reserved_nanodollars = max(0, (int) $lockedWallet->reserved_nanodollars - $reserved);
            $lockedWallet->save();

            $lockedGeneration->reserved_user_cost_nano = 0;
            $lockedGeneration->actual_provider_cost_nano = $actualProviderCostNano;
            $lockedGeneration->actual_user_cost_nano = $actualUserCostNano;
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
