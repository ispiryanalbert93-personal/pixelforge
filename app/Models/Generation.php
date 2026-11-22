<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Money;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $pixelforge_id
 * @property string $idempotency_key
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property array<int, string>|null $reference_images
 * @property array<string, string>|null $locked_rates
 * @property array<string, int>|null $usage
 * @property int $estimated_min_user_cost_nano
 * @property int $estimated_user_cost_nano
 * @property int $estimated_max_user_cost_nano
 * @property int $reserved_user_cost_nano
 * @property int|null $actual_provider_cost_nano
 * @property int|null $actual_user_cost_nano
 * @property string|null $image_url
 * @property string|null $error_code
 * @property string|null $error_message
 * @property-read string $estimated_user_cost_usd
 * @property-read string|null $actual_user_cost_usd
 * @property-read string|null $actual_provider_cost_usd
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'pixelforge_id',
        'idempotency_key',
        'status',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'locked_rates',
        'usage',
        'estimated_min_user_cost_nano',
        'estimated_user_cost_nano',
        'estimated_max_user_cost_nano',
        'reserved_user_cost_nano',
        'actual_provider_cost_nano',
        'actual_user_cost_nano',
        'settled_at',
        'image_url',
        'image_url_expires_at',
        'error_code',
        'error_message',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'locked_rates' => 'array',
            'usage' => 'array',
            'estimated_min_user_cost_nano' => 'integer',
            'estimated_user_cost_nano' => 'integer',
            'estimated_max_user_cost_nano' => 'integer',
            'reserved_user_cost_nano' => 'integer',
            'actual_provider_cost_nano' => 'integer',
            'actual_user_cost_nano' => 'integer',
            'settled_at' => 'datetime',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function estimatedUserCostUsd(): Attribute
    {
        return Attribute::get(
            fn (): string => Money::nanoToUsd((int) $this->estimated_user_cost_nano),
        );
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function actualUserCostUsd(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->actual_user_cost_nano !== null
                ? Money::nanoToUsd((int) $this->actual_user_cost_nano)
                : null,
        );
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function actualProviderCostUsd(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->actual_provider_cost_nano !== null
                ? Money::nanoToUsd((int) $this->actual_provider_cost_nano)
                : null,
        );
    }

    public function canTransitionTo(GenerationStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function transitionTo(GenerationStatus $next): bool
    {
        if (! $this->canTransitionTo($next)) {
            return false;
        }

        $this->status = $next;
        $this->save();

        return true;
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
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
