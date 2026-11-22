<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredNano,
        public readonly int $availableNano,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? sprintf(
                'Insufficient credits: required %d nanodollars, available %d nanodollars.',
                $requiredNano,
                $availableNano,
            )
        );
    }
}
