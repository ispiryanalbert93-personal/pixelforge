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
        return [
            'balance_nanodollars' => (int) $this->balance_nanodollars,
            'balance_usd' => $this->balance_usd,
            'balance_formatted' => Money::formatDisplay((int) $this->balance_nanodollars),
            'reserved_nanodollars' => (int) $this->reserved_nanodollars,
            'reserved_usd' => $this->reserved_usd,
            'reserved_formatted' => Money::formatDisplay((int) $this->reserved_nanodollars),
        ];
    }
}
