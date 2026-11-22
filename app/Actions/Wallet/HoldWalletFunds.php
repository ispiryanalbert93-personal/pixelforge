<?php

namespace App\Actions\Wallet;

use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Money;
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
        int|float|string $holdNano,
        ?string $description = null,
    ): WalletTransaction {
        $normalizedHold = Money::normalizeNano($holdNano);

        if (Money::compare($normalizedHold, 0) <= 0) {
            throw new InvalidArgumentException("Hold amount must be positive, got {$normalizedHold}.");
        }

        $transaction = DB::transaction(function () use ($user, $generation, $normalizedHold, $description): WalletTransaction {
            $lockedWallet = $this->lockUserWallet->handle((int) $user->getKey());

            $lockedGeneration = Generation::whereKey($generation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (Money::compare($lockedGeneration->reserved_user_cost_nano ?? 0, 0) > 0 || $lockedGeneration->settled_at !== null) {
                throw new InvalidArgumentException("Generation {$lockedGeneration->id} already has a hold or has been settled.");
            }

            $available = Money::normalizeNano($lockedWallet->balance_nanodollars ?? 0);

            if (Money::compare($available, $normalizedHold) < 0) {
                throw new InsufficientCreditsException($normalizedHold, $available);
            }

            $lockedWallet->balance_nanodollars = Money::sub($available, $normalizedHold);
            $lockedWallet->reserved_nanodollars = Money::add($lockedWallet->reserved_nanodollars ?? 0, $normalizedHold);
            $lockedWallet->save();

            $lockedGeneration->reserved_user_cost_nano = Money::toIntOrString($normalizedHold);
            $lockedGeneration->save();

            return WalletTransaction::create([
                'user_id' => $lockedWallet->user_id,
                'wallet_id' => $lockedWallet->id,
                'generation_id' => $lockedGeneration->id,
                'type' => WalletTransactionType::Hold,
                'amount_nanodollars' => Money::negate($normalizedHold),
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
