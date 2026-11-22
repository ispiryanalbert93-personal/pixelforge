<?php

namespace App\Actions\Wallet;

use App\Enums\WalletTransactionType;
use App\Models\Generation;
use App\Models\WalletTransaction;

class ReleaseWalletHold
{
    public function __construct(
        private readonly FinalizeWalletSettlement $finalizeWalletSettlement,
    ) {}

    /**
     * Release the full wallet hold for a failed generation at zero cost.
     */
    public function handle(
        Generation $generation,
        ?string $description = null,
    ): ?WalletTransaction {
        return $this->finalizeWalletSettlement->handle(
            generation: $generation,
            type: WalletTransactionType::Release,
            actualProviderCostNano: 0,
            actualUserCostNano: 0,
            description: $description ?? "Released hold for failed generation {$generation->id}",
        );
    }
}
