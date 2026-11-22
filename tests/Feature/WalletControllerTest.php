<?php

namespace Tests\Feature;

use App\Actions\Wallet\HoldWalletFunds;
use App\Actions\Wallet\TopUpWallet;
use App\Enums\WalletTransactionType;
use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_top_up_wallet(): void
    {
        $this->post('/wallet', ['amount_usd' => '10.00'])
            ->assertRedirect('/login');
    }

    public function test_top_up_validates_amount_usd_bounds_and_type(): void
    {
        $user = User::factory()->create();

        foreach ([null, '', 'not-a-number', '0.49', '1000.01', '1e2', '10.123'] as $invalidAmount) {
            $this->actingAs($user)
                ->from('/dashboard')
                ->post('/wallet', ['amount_usd' => $invalidAmount])
                ->assertRedirect('/dashboard')
                ->assertSessionHasErrors(['amount_usd']);
        }

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0, $user->fresh()->wallet->balance_nanodollars);
    }

    public function test_top_up_adds_exact_nanodollars_records_wallet_transaction_and_redirects_with_flash_success(): void
    {
        $user = User::factory()->withBalance(500_000_000)->create();

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->post('/wallet', [
                'amount_usd' => '25.50',
            ]);

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('success');

        $expectedAddedNano = Money::usdToNano('25.50');
        $this->assertSame(25_500_000_000, $expectedAddedNano);
        $this->assertSame(26_000_000_000, $user->fresh()->wallet->balance_nanodollars);

        $transaction = WalletTransaction::query()->firstOrFail();
        $this->assertSame($user->id, $transaction->user_id);
        $this->assertSame($user->wallet->id, $transaction->wallet_id);
        $this->assertNull($transaction->generation_id);
        $this->assertSame(WalletTransactionType::TopUp, $transaction->type);
        $this->assertSame(25_500_000_000, $transaction->amount_nanodollars);
        $this->assertSame(26_000_000_000, $transaction->balance_after_nanodollars);
    }

    public function test_admin_users_cannot_top_up_wallet(): void
    {
        $admin = User::factory()->admin()->withBalance(0)->create();

        $this->actingAs($admin)
            ->post('/wallet', ['amount_usd' => '10.00'])
            ->assertForbidden();

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0, $admin->fresh()->wallet->balance_nanodollars);
    }

    public function test_guests_and_admins_cannot_access_wallet_transactions_endpoint(): void
    {
        $this->getJson('/wallet')->assertUnauthorized();
        $this->get('/wallet')->assertRedirect('/login');

        $admin = User::factory()->admin()->withBalance(0)->create();

        $this->actingAs($admin)
            ->getJson('/wallet')
            ->assertForbidden();
    }

    public function test_customer_can_fetch_their_own_paginated_wallet_transactions_via_json(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $topUpWallet = app(TopUpWallet::class);
        $topUpWallet->handle($user, 5_000_000_000, 'Initial deposit');
        $topUpWallet->handle($otherUser, 9_000_000_000, 'Other user deposit');

        for ($i = 1; $i <= 11; $i++) {
            $generation = Generation::factory()->for($user)->create();
            app(HoldWalletFunds::class)->handle($user, $generation, 100_000_000, "Hold #{$i}");
        }

        $pageOne = $this->actingAs($user)->getJson(route('wallet.index'));

        $pageOne->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('data.0.type', WalletTransactionType::Hold->value)
            ->assertJsonPath('data.0.description', 'Hold #11');

        $pageTwo = $this->actingAs($user)->getJson(route('wallet.index', ['page' => 2]));

        $pageTwo->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.description', 'Hold #1')
            ->assertJsonPath('data.1.type', WalletTransactionType::TopUp->value)
            ->assertJsonPath('data.1.description', 'Initial deposit');
    }
}
