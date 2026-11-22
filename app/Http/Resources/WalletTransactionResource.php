<?php

namespace App\Http\Resources;

use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WalletTransaction
 */
class WalletTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $amountNano = $this->amount_nanodollars ?? 0;
        $balanceAfterNano = $this->balance_after_nanodollars ?? 0;
        $reservedAfterNano = $this->reserved_after_nanodollars ?? 0;

        return [
            'id' => $this->id,
            'generation_id' => $this->generation_id !== null ? (int) $this->generation_id : null,
            'type' => $this->type->value,
            'amount_nanodollars' => Money::toNumber($amountNano),
            'amount_usd' => $this->amount_usd,
            'amount_formatted' => Money::formatDisplay($amountNano),
            'balance_after_nanodollars' => Money::toNumber($balanceAfterNano),
            'balance_after_usd' => $this->balance_after_usd,
            'reserved_after_nanodollars' => Money::toNumber($reservedAfterNano),
            'reserved_after_usd' => $this->reserved_after_usd,
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }
}
