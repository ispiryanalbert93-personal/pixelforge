<?php

namespace App\Actions\Wallet;

use App\Models\Wallet;

class LockUserWallet
{
    /**
     * Lock and return the user's wallet for update.
     */
    public function handle(int $userId): Wallet
    {
        return Wallet::where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
