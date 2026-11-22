<?php

namespace App\Exceptions;

use App\Support\Money;
use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public readonly int|string $requiredNano;

    public readonly int|string $availableNano;

    public function __construct(
        int|float|string $requiredNano,
        int|float|string $availableNano,
        ?string $message = null,
    ) {
        $this->requiredNano = Money::toIntOrString($requiredNano);
        $this->availableNano = Money::toIntOrString($availableNano);

        parent::__construct(
            $message ?? sprintf(
                'Insufficient credits: required %s nanodollars, available %s nanodollars.',
                Money::normalizeNano($this->requiredNano),
                Money::normalizeNano($this->availableNano),
            )
        );
    }
}
