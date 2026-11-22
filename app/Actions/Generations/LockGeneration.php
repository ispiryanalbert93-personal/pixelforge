<?php

namespace App\Actions\Generations;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LockGeneration
{
    /**
     * Acquire cache and pessimistic DB locks (User first, then Generation) inside a transaction.
     *
     * @template TReturn
     *
     * @param  callable(Generation): TReturn  $callback
     * @return TReturn
     */
    public function handle(Generation $generation, callable $callback): mixed
    {
        return Cache::lock("generation-sync:{$generation->id}", 10)
            ->block(5, fn () => DB::transaction(function () use ($generation, $callback) {
                User::whereKey($generation->user_id)->lockForUpdate()->firstOrFail();
                $locked = Generation::whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

                return $callback($locked);
            }));
    }
}
