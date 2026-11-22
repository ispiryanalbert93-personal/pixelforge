<?php

namespace App\Models;

use App\Casts\NanodollarCast;
use App\Support\Money;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read string $balance_usd
 * @property-read string $reserved_usd
 */
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_nanodollars' => NanodollarCast::class,
            'reserved_nanodollars' => NanodollarCast::class,
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function balanceUsd(): Attribute
    {
        return Attribute::get(
            fn (): string => Money::nanoToUsd($this->balance_nanodollars ?? 0),
        );
    }

    /**
     * @return Attribute<string, never>
     */
    protected function reservedUsd(): Attribute
    {
        return Attribute::get(
            fn (): string => Money::nanoToUsd($this->reserved_nanodollars ?? 0),
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
     * @return HasMany<WalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
