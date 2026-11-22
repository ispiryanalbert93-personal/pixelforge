<?php

namespace App\Jobs;

use App\Services\Pixelforge\WebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly int $webhookEventId,
    ) {}

    public function handle(WebhookService $webhookService): void
    {
        $webhookService->processStoredEvent($this->webhookEventId);
    }
}
