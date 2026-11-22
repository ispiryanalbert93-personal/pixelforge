<?php

namespace App\Actions\Wallet;

use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class HoldWalletFunds
{
    public function __construct(
        private readonly LockUserWallet $lockUserWallet,
    ) {}

    /**
     * Reserve funds in a user's wallet for an in-flight generation.
     */
    public function handle(
        User $user,
        Generation $generation,
        int $holdNano,
        ?string $description = null,
    ): WalletTransaction {
        if ($holdNano <= 0) {
            throw new InvalidArgumentException("Hold amount must be positive, got {$holdNano}.");
        }

        $transaction = DB::transaction(function () use ($user, $generation, $holdNano, $description): WalletTransaction {
            $lockedWallet = $this->lockUserWallet->handle((int) $user->getKey());

            $lockedGeneration = Generation::whereKey($generation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedGeneration->reserved_user_cost_nano > 0 || $lockedGeneration->settled_at !== null) {
                throw new InvalidArgumentException("Generation {$lockedGeneration->id} already has a hold or has been settled.");
            }

            $available = (int) $lockedWallet->balance_nanodollars;

            if ($available < $holdNano) {
                throw new InsufficientCreditsException($holdNano, $available);
            }

            $lockedWallet->balance_nanodollars = $available - $holdNano;
            $lockedWallet->reserved_nanodollars += $holdNano;
            $lockedWallet->save();

            $lockedGeneration->reserved_user_cost_nano = $holdNano;
            $lockedGeneration->save();

            return WalletTransaction::create([
                'user_id' => $lockedWallet->user_id,
                'wallet_id' => $lockedWallet->id,
                'generation_id' => $lockedGeneration->id,
                'type' => WalletTransactionType::Hold,
                'amount_nanodollars' => -$holdNano,
                'balance_after_nanodollars' => $lockedWallet->balance_nanodollars,
                'reserved_after_nanodollars' => $lockedWallet->reserved_nanodollars,
                'description' => $description ?? "Hold for generation {$lockedGeneration->id}",
            ]);
        });

        $user->refresh();
        $generation->refresh();

        return $transaction;
    }
}
