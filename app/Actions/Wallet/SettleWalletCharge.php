<?php

namespace App\Actions\Wallet;

use App\Enums\WalletTransactionType;
use App\Models\Generation;
use App\Models\WalletTransaction;
use App\Support\Money;
use InvalidArgumentException;

class SettleWalletCharge
{
    public function __construct(
        private readonly FinalizeWalletSettlement $finalizeWalletSettlement,
    ) {}

    /**
     * Settle a succeeded generation against its wallet hold and refund any excess reserve.
     */
    public function handle(
        Generation $generation,
        int $actualProviderCostNano,
        int $actualUserCostNano,
        ?string $description = null,
    ): ?WalletTransaction {
        if ($actualProviderCostNano < 0) {
            throw new InvalidArgumentException("Actual provider cost must be non-negative, got {$actualProviderCostNano}.");
        }

        if ($actualUserCostNano < 0) {
            throw new InvalidArgumentException("Actual user cost must be non-negative, got {$actualUserCostNano}.");
        }

        $formattedCost = Money::formatDisplay($actualUserCostNano);

        return $this->finalizeWalletSettlement->handle(
            generation: $generation,
            type: WalletTransactionType::Charge,
            actualProviderCostNano: $actualProviderCostNano,
            actualUserCostNano: $actualUserCostNano,
            description: $description ?? "Settled generation {$generation->id} (charged {$formattedCost})",
        );
    }
}
