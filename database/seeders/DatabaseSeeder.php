<?php

namespace Database\Seeders;

use App\Actions\Wallet\TopUpWallet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->firstOrNew(['email' => 'admin@example.com']);
        if (! $admin->exists) {
            $admin->forceFill([
                'name' => 'Operator Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_admin' => true,
            ])->save();
        } elseif (! $admin->is_admin) {
            $admin->forceFill(['is_admin' => true])->save();
        }

        $demoUser = User::query()->firstOrNew(['email' => 'test@example.com']);
        if (! $demoUser->exists) {
            $demoUser->forceFill([
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_admin' => false,
            ])->save();
        } elseif ($demoUser->is_admin) {
            $demoUser->forceFill(['is_admin' => false])->save();
        }

        if ((int) $demoUser->wallet->balance_nanodollars === 0) {
            app(TopUpWallet::class)->handle(
                user: $demoUser,
                amountNano: 25_000_000_000,
                description: 'Initial demo user credits ($25.00)',
            );
        }
    }
}
