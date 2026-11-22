<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Preserves 64-bit nanodollar integers across both 64-bit and 32-bit (wasm32) PHP runtimes.
 *
 * @implements CastsAttributes<int|string|null, int|float|string|null>
 */
class NanodollarCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|string|null
    {
        if ($value === null) {
            return null;
        }

        return Money::toIntOrString($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Money::normalizeNano($value);
    }
}
