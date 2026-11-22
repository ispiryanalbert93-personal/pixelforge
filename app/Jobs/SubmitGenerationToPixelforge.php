<?php

namespace App\Jobs;

use App\Services\Pixelforge\GenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitGenerationToPixelforge implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $generationId,
    ) {}

    public function handle(GenerationService $generationService): void
    {
        $isAsyncQueue = $this->job !== null && $this->job->getConnectionName() !== 'sync';

        $retryDelaySeconds = $generationService->submitGeneration(
            generationId: $this->generationId,
            isAsyncQueue: $isAsyncQueue,
            attempt: $this->attempts(),
            maxTries: $this->tries,
        );

        if ($retryDelaySeconds !== null && $isAsyncQueue) {
            $this->release($retryDelaySeconds);
        }
    }
}
