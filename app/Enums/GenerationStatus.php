<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            self::Queued, self::Processing => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Queued => in_array($next, [self::Processing, self::Succeeded, self::Failed], true),
            self::Processing => in_array($next, [self::Succeeded, self::Failed], true),
            self::Succeeded, self::Failed => false,
        };
    }
}
