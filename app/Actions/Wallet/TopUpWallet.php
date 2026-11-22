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
    public function handle(User $user, int $amountNano, ?string $description = null): WalletTransaction
    {
        if ($amountNano <= 0) {
            throw new InvalidArgumentException("Top-up amount must be positive, got {$amountNano}.");
        }

        $transaction = DB::transaction(function () use ($user, $amountNano, $description): WalletTransaction {
            $lockedWallet = $this->lockUserWallet->handle((int) $user->getKey());

            $lockedWallet->balance_nanodollars += $amountNano;
            $lockedWallet->save();

            $formattedAmount = Money::formatDisplay($amountNano, 2);

            return WalletTransaction::create([
                'user_id' => $lockedWallet->user_id,
                'wallet_id' => $lockedWallet->id,

                'type' => WalletTransactionType::TopUp,
                'description' => $description ?? "Wallet top-up ({$formattedAmount})",

                'amount_nanodollars' => $amountNano,
                'balance_after_nanodollars' => $lockedWallet->balance_nanodollars,
                'reserved_after_nanodollars' => $lockedWallet->reserved_nanodollars,
            ]);
        });

        $user->refresh();

        return $transaction;
    }
}
