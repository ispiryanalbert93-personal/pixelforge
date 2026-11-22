<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the wallet resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $balanceNano = $this->balance_nanodollars ?? 0;
        $reservedNano = $this->reserved_nanodollars ?? 0;

        return [
            'balance_nanodollars' => Money::toNumber($balanceNano),
            'balance_usd' => $this->balance_usd,
            'balance_formatted' => Money::formatDisplay($balanceNano),
            'reserved_nanodollars' => Money::toNumber($reservedNano),
            'reserved_usd' => $this->reserved_usd,
            'reserved_formatted' => Money::formatDisplay($reservedNano),
        ];
    }
}
