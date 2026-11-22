<?php

namespace App\Models;

use App\Casts\NanodollarCast;
use App\Enums\WalletTransactionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $amount_usd
 * @property-read string $balance_after_usd
 * @property-read string $reserved_after_usd
 */
class WalletTransaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'wallet_id',
        'generation_id',
        'type',
        'amount_nanodollars',
        'balance_after_nanodollars',
        'reserved_after_nanodollars',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount_nanodollars' => NanodollarCast::class,
            'balance_after_nanodollars' => NanodollarCast::class,
            'reserved_after_nanodollars' => NanodollarCast::class,
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function amountUsd(): Attribute
    {
        return Attribute::get(
            fn (): string => Money::nanoToUsd($this->amount_nanodollars ?? 0),
        );
    }

    /**
     * @return Attribute<string, never>
     */
    protected function balanceAfterUsd(): Attribute
    {
        return Attribute::get(
            fn (): string => Money::nanoToUsd($this->balance_after_nanodollars ?? 0),
        );
    }

    /**
     * @return Attribute<string, never>
     */
    protected function reservedAfterUsd(): Attribute
    {
        return Attribute::get(
            fn (): string => Money::nanoToUsd($this->reserved_after_nanodollars ?? 0),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
