<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Services\Pixelforge\GenerationService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

class ReconcileStuckGenerations extends Command
{
    protected $signature = 'generations:reconcile {--minutes=10 : Minimum age in minutes before reconciling non-terminal generations}';

    protected $description = 'Reconcile stuck non-terminal generations against Pixelforge GET /v1/generations/{id}';

    public function handle(GenerationService $generationService): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $stuckGenerations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        if ($stuckGenerations->isEmpty()) {
            $this->info('No stuck generations found.');

            return self::SUCCESS;
        }

        $reconciled = 0;
        $failed = 0;

        foreach ($stuckGenerations as $generation) {
            try {
                $generationService->syncFromProvider($generation);
                $reconciled++;
            } catch (PixelforgeApiException|ConnectionException $exception) {
                $failed++;
                $this->warn("Failed to reconcile generation {$generation->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Reconciled {$reconciled} generation(s) ({$failed} failed).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
