<?php

namespace App\Actions\Generations;

use App\Actions\Wallet\ReleaseWalletHold;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Carbon;

class FailGeneration
{
    public function __construct(
        private readonly ReleaseWalletHold $releaseWalletHold,
    ) {}

    /**
     * Mark a locked generation as failed and release its reserved wallet hold.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public function handle(
        Generation $locked,
        string $errorCode,
        ?string $errorMessage,
        ?Carbon $completedAt = null,
        ?array $usage = null,
    ): void {
        $locked->forceFill([
            'status' => GenerationStatus::Failed,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'usage' => $usage ?? $locked->usage,
            'completed_at' => $completedAt ?? $locked->completed_at ?? now(),
        ])->save();

        $this->releaseWalletHold->handle($locked);
    }
}
