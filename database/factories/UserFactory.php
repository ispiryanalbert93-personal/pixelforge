<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_admin' => false,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Mark the user as an administrator.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    /**
     * Seed the user's wallet with an initial available balance in nanodollars.
     */
    public function withBalance(int|float|string $nano): static
    {
        return $this->withWallet($nano);
    }

    /**
     * Seed the user's wallet with explicit available and reserved nanodollar balances.
     */
    public function withWallet(int|float|string $balanceNano = 0, int|float|string $reservedNano = 0): static
    {
        return $this->afterCreating(function (User $user) use ($balanceNano, $reservedNano): void {
            $wallet = $user->wallet()->firstOrCreate();
            $wallet->forceFill([
                'balance_nanodollars' => Money::toIntOrString($balanceNano),
                'reserved_nanodollars' => Money::toIntOrString($reservedNano),
            ])->save();
        });
    }
}
