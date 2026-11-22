<?php

namespace App\Actions\Wallet;

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TopUpWallet
{
    public function __construct(
        private readonly LockUserWallet $lockUserWallet,
    ) {}

    /**
     * Add funds to a user's wallet and record a top-up transaction.
     */
    public function handle(User $user, int|float|string $amountNano, ?string $description = null): WalletTransaction
    {
        $normalizedAmount = Money::normalizeNano($amountNano);

        if (Money::compare($normalizedAmount, 0) <= 0) {
            throw new InvalidArgumentException("Top-up amount must be positive, got {$normalizedAmount}.");
        }

        $transaction = DB::transaction(function () use ($user, $normalizedAmount, $description): WalletTransaction {
            $lockedWallet = $this->lockUserWallet->handle((int) $user->getKey());

            $lockedWallet->balance_nanodollars = Money::add($lockedWallet->balance_nanodollars ?? 0, $normalizedAmount);
            $lockedWallet->save();

            $formattedAmount = Money::formatDisplay($normalizedAmount, 2);

            return WalletTransaction::create([
                'user_id' => $lockedWallet->user_id,
                'wallet_id' => $lockedWallet->id,
                'type' => WalletTransactionType::TopUp,
                'description' => $description ?? "Wallet top-up ({$formattedAmount})",
                'amount_nanodollars' => $normalizedAmount,
                'balance_after_nanodollars' => $lockedWallet->balance_nanodollars,
                'reserved_after_nanodollars' => $lockedWallet->reserved_nanodollars,
            ]);
        });

        $user->refresh();

        return $transaction;
    }
}
