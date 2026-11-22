<?php

namespace App\Enums;

enum ImageSize: string
{
    case Square = '1024x1024';
    case Landscape = '1536x1024';
    case Portrait = '1024x1536';

    public function label(): string
    {
        return match ($this) {
            self::Square => 'Square (1024x1024)',
            self::Landscape => 'Landscape (1536x1024)',
            self::Portrait => 'Portrait (1024x1536)',
        };
    }

    public function isWideOrTall(): bool
    {
        return $this !== self::Square;
    }
}
