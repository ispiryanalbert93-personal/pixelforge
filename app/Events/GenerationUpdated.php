<?php

namespace App\Events;

use App\Http\Resources\GenerationResource;
use App\Http\Resources\WalletResource;
use App\Models\Generation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Generation $generation,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->generation->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'generation.updated';
    }

    public function broadcastWhen(): bool
    {
        if (config('broadcasting.default') === 'pusher' && empty(config('broadcasting.connections.pusher.key'))) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $generation = $this->generation->fresh() ?? $this->generation;
        $user = $generation->user()->firstOrFail();

        return [
            'generation' => GenerationResource::make($generation)->resolve(),
            'wallet' => WalletResource::make($user->wallet)->resolve(),
            'has_active_generations' => $user->hasActiveGenerations(),
        ];
    }

    public static function dispatchSafely(Generation $generation): void
    {
        try {
            self::dispatch($generation->fresh() ?? $generation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
