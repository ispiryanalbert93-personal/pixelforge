<?php

namespace App\Actions\Generations;

use App\Actions\Wallet\LockUserWallet;
use App\Models\Generation;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class LockGeneration
{
    public function __construct(
        private readonly LockUserWallet $lockUserWallet,
    ) {}

    /**
     * Acquire pessimistic DB locks in canonical order (Wallet first, then Generation) inside one transaction.
     *
     * @template TReturn
     *
     * @param  callable(Generation, Wallet): TReturn  $callback
     * @return TReturn
     */
    public function handle(Generation $generation, callable $callback): mixed
    {
        return DB::transaction(function () use ($generation, $callback) {
            $lockedWallet = $this->lockUserWallet->handle((int) $generation->user_id);
            $lockedGeneration = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            return $callback($lockedGeneration, $lockedWallet);
        });
    }
}
