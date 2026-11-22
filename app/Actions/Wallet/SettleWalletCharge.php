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
        int|float|string $actualProviderCostNano,
        int|float|string $actualUserCostNano,
        ?string $description = null,
    ): ?WalletTransaction {
        $providerCost = Money::normalizeNano($actualProviderCostNano);
        $userCost = Money::normalizeNano($actualUserCostNano);

        if (Money::compare($providerCost, 0) < 0) {
            throw new InvalidArgumentException("Actual provider cost must be non-negative, got {$providerCost}.");
        }

        if (Money::compare($userCost, 0) < 0) {
            throw new InvalidArgumentException("Actual user cost must be non-negative, got {$userCost}.");
        }

        $formattedCost = Money::formatDisplay($userCost);

        return $this->finalizeWalletSettlement->handle(
            generation: $generation,
            type: WalletTransactionType::Charge,
            actualProviderCostNano: $providerCost,
            actualUserCostNano: $userCost,
            description: $description ?? "Settled generation {$generation->id} (charged {$formattedCost})",
        );
    }
}
