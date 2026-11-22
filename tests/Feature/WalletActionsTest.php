<?php

namespace Tests\Feature;

use App\Actions\Wallet\HoldWalletFunds;
use App\Actions\Wallet\ReleaseWalletHold;
use App\Actions\Wallet\SettleWalletCharge;
use App\Actions\Wallet\TopUpWallet;
use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use Database\Seeders\WalletTransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WalletActionsTest extends TestCase
{
    use RefreshDatabase;

    private TopUpWallet $topUpWallet;

    private HoldWalletFunds $holdWalletFunds;

    private SettleWalletCharge $settleWalletCharge;

    private ReleaseWalletHold $releaseWalletHold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->topUpWallet = app(TopUpWallet::class);
        $this->holdWalletFunds = app(HoldWalletFunds::class);
        $this->settleWalletCharge = app(SettleWalletCharge::class);
        $this->releaseWalletHold = app(ReleaseWalletHold::class);
    }

    public function test_top_up_increases_balance_and_creates_wallet_transaction(): void
    {
        $user = User::factory()->create();

        $transaction = $this->topUpWallet->handle($user, 10_000_000_000, 'Starter pack');

        $this->assertSame(10_000_000_000, $user->wallet->balance_nanodollars);
        $this->assertSame(10_000_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame('10.000000000', $user->wallet->balance_usd);
        $this->assertSame('0.000000000', $user->wallet->reserved_usd);

        $this->assertInstanceOf(WalletTransaction::class, $transaction);
        $this->assertSame($user->id, $transaction->user_id);
        $this->assertSame($user->wallet->id, $transaction->wallet_id);
        $this->assertNull($transaction->generation_id);
        $this->assertSame(WalletTransactionType::TopUp, $transaction->type);
        $this->assertSame(10_000_000_000, $transaction->amount_nanodollars);
        $this->assertSame(10_000_000_000, $transaction->balance_after_nanodollars);
        $this->assertSame(0, $transaction->reserved_after_nanodollars);
        $this->assertSame('Starter pack', $transaction->description);
    }

    public function test_top_up_rejects_zero_or_negative_amounts(): void
    {
        $user = User::factory()->withBalance(5_000_000_000)->create();

        try {
            $this->topUpWallet->handle($user, 0, 'Invalid zero');
            $this->fail('Expected InvalidArgumentException for zero top-up.');
        } catch (InvalidArgumentException) {
            // expected
        }

        try {
            $this->topUpWallet->handle($user, -100, 'Invalid negative');
            $this->fail('Expected InvalidArgumentException for negative top-up.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(5_000_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_hold_for_generation_reserves_funds_and_records_hold_transaction(): void
    {
        $user = User::factory()->withBalance(100_000_000)->create();
        $generation = Generation::factory()->for($user)->create();

        $transaction = $this->holdWalletFunds->handle($user, $generation, 72_000_000);

        $this->assertSame(28_000_000, $user->wallet->balance_nanodollars);
        $this->assertSame(72_000_000, $user->wallet->reserved_nanodollars);
        $this->assertSame(28_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(72_000_000, $user->fresh()->wallet->reserved_nanodollars);

        $this->assertSame(72_000_000, $generation->reserved_user_cost_nano);
        $this->assertSame(72_000_000, $generation->fresh()->reserved_user_cost_nano);

        $this->assertSame(WalletTransactionType::Hold, $transaction->type);
        $this->assertSame($user->id, $transaction->user_id);
        $this->assertSame($user->wallet->id, $transaction->wallet_id);
        $this->assertSame($generation->id, $transaction->generation_id);
        $this->assertSame(-72_000_000, $transaction->amount_nanodollars);
        $this->assertSame(28_000_000, $transaction->balance_after_nanodollars);
        $this->assertSame(72_000_000, $transaction->reserved_after_nanodollars);
    }

    public function test_hold_for_generation_blocks_when_available_balance_is_insufficient(): void
    {
        $user = User::factory()->withWallet(50_000_000, 100_000_000)->create();
        $generation = Generation::factory()->for($user)->create();

        try {
            $this->holdWalletFunds->handle($user, $generation, 60_000_000);
            $this->fail('Expected InsufficientCreditsException.');
        } catch (InsufficientCreditsException $exception) {
            $this->assertSame(60_000_000, $exception->requiredNano);
            $this->assertSame(50_000_000, $exception->availableNano);
        }

        $freshWallet = $user->fresh()->wallet;
        $this->assertSame(50_000_000, $freshWallet->balance_nanodollars);
        $this->assertSame(100_000_000, $freshWallet->reserved_nanodollars);
        $this->assertSame(0, $generation->fresh()->reserved_user_cost_nano);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_settle_generation_refunds_unused_hold_and_records_charge_transaction(): void
    {
        $user = User::factory()->withBalance(100_000_000)->create();
        $generation = Generation::factory()->for($user)->create();
        $this->holdWalletFunds->handle($user, $generation, 72_000_000);

        $chargeTransaction = $this->settleWalletCharge->handle($generation, 46_115_000, 57_643_750);

        $freshWallet = $user->fresh()->wallet;
        $freshGen = $generation->fresh();

        // Unused hold = 72_000_000 - 57_643_750 = 14_356_250
        // Balance = 28_000_000 + 14_356_250 = 42_356_250 (net deduction = 57_643_750)
        $this->assertSame(42_356_250, $freshWallet->balance_nanodollars);
        $this->assertSame(0, $freshWallet->reserved_nanodollars);

        $this->assertSame(0, $freshGen->reserved_user_cost_nano);
        $this->assertSame(46_115_000, $freshGen->actual_provider_cost_nano);
        $this->assertSame(57_643_750, $freshGen->actual_user_cost_nano);
        $this->assertNotNull($freshGen->settled_at);

        $this->assertInstanceOf(WalletTransaction::class, $chargeTransaction);
        $this->assertSame(WalletTransactionType::Charge, $chargeTransaction->type);
        $this->assertSame($user->id, $chargeTransaction->user_id);
        $this->assertSame($freshWallet->id, $chargeTransaction->wallet_id);
        $this->assertSame($generation->id, $chargeTransaction->generation_id);
        $this->assertSame(14_356_250, $chargeTransaction->amount_nanodollars);
        $this->assertSame(42_356_250, $chargeTransaction->balance_after_nanodollars);
        $this->assertSame(0, $chargeTransaction->reserved_after_nanodollars);
    }

    public function test_release_hold_for_failed_generation_refunds_full_hold_and_costs_nothing(): void
    {
        $user = User::factory()->withBalance(100_000_000)->create();
        $generation = Generation::factory()->for($user)->create();
        $this->holdWalletFunds->handle($user, $generation, 72_000_000);

        $releaseTransaction = $this->releaseWalletHold->handle($generation);

        $freshWallet = $user->fresh()->wallet;
        $freshGen = $generation->fresh();

        $this->assertSame(100_000_000, $freshWallet->balance_nanodollars);
        $this->assertSame(0, $freshWallet->reserved_nanodollars);

        $this->assertSame(0, $freshGen->reserved_user_cost_nano);
        $this->assertSame(0, $freshGen->actual_provider_cost_nano);
        $this->assertSame(0, $freshGen->actual_user_cost_nano);
        $this->assertNotNull($freshGen->settled_at);

        $this->assertInstanceOf(WalletTransaction::class, $releaseTransaction);
        $this->assertSame(WalletTransactionType::Release, $releaseTransaction->type);
        $this->assertSame($user->id, $releaseTransaction->user_id);
        $this->assertSame($freshWallet->id, $releaseTransaction->wallet_id);
        $this->assertSame($generation->id, $releaseTransaction->generation_id);
        $this->assertSame(72_000_000, $releaseTransaction->amount_nanodollars);
        $this->assertSame(100_000_000, $releaseTransaction->balance_after_nanodollars);
        $this->assertSame(0, $releaseTransaction->reserved_after_nanodollars);
    }

    public function test_settle_generation_is_idempotent_and_prevents_double_charge_or_release(): void
    {
        $user = User::factory()->withBalance(100_000_000)->create();
        $generation = Generation::factory()->for($user)->create();
        $this->holdWalletFunds->handle($user, $generation, 72_000_000);

        $first = $this->settleWalletCharge->handle($generation, 46_115_000, 57_643_750);
        $second = $this->settleWalletCharge->handle($generation, 46_115_000, 57_643_750);
        $afterRelease = $this->releaseWalletHold->handle($generation);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertNull($afterRelease);

        $this->assertSame(42_356_250, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);
        $this->assertDatabaseCount('wallet_transactions', 2); // Hold + Charge only
    }

    public function test_release_hold_is_idempotent_and_prevents_double_release_or_subsequent_settle(): void
    {
        $user = User::factory()->withBalance(100_000_000)->create();
        $generation = Generation::factory()->for($user)->create();
        $this->holdWalletFunds->handle($user, $generation, 72_000_000);

        $first = $this->releaseWalletHold->handle($generation);
        $second = $this->releaseWalletHold->handle($generation);
        $afterSettle = $this->settleWalletCharge->handle($generation, 46_115_000, 57_643_750);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertNull($afterSettle);

        $this->assertSame(100_000_000, $user->fresh()->wallet->balance_nanodollars);
        $this->assertSame(0, $user->fresh()->wallet->reserved_nanodollars);
        $this->assertDatabaseCount('wallet_transactions', 2); // Hold + Release only
    }

    public function test_hold_for_generation_rejects_duplicate_hold_on_same_generation(): void
    {
        $user = User::factory()->withBalance(500_000_000)->create();
        $generation = Generation::factory()->for($user)->create();

        $this->holdWalletFunds->handle($user, $generation, 72_000_000);

        try {
            $this->holdWalletFunds->handle($user, $generation, 72_000_000);
            $this->fail('Expected InvalidArgumentException on duplicate hold.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $freshWallet = $user->fresh()->wallet;
        $this->assertSame(428_000_000, $freshWallet->balance_nanodollars);
        $this->assertSame(72_000_000, $freshWallet->reserved_nanodollars);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_wallet_transaction_seeder_creates_sample_transactions_across_all_types(): void
    {
        $user = User::factory()->create();

        $this->seed(WalletTransactionSeeder::class);

        $this->assertDatabaseCount('wallet_transactions', 6);
        $this->assertSame(1, $user->walletTransactions()->where('type', WalletTransactionType::TopUp)->count());
        $this->assertSame(3, $user->walletTransactions()->where('type', WalletTransactionType::Hold)->count());
        $this->assertSame(1, $user->walletTransactions()->where('type', WalletTransactionType::Charge)->count());
        $this->assertSame(1, $user->walletTransactions()->where('type', WalletTransactionType::Release)->count());
    }

    public function test_wallet_actions_handle_float_and_string_nanodollars_for_32bit_runtimes(): void
    {
        $user = User::factory()->create();

        // Simulate 32-bit PHP float literal / numeric string > 2^31 - 1 ($25.00 + $10.00)
        $this->topUpWallet->handle($user, 25_000_000_000.0, 'Float top-up');
        $this->topUpWallet->handle($user, '10000000000', 'String top-up');

        $this->assertSame('35.000000000', $user->fresh()->wallet->balance_usd);

        $generation = Generation::factory()->for($user)->create();
        $this->holdWalletFunds->handle($user, $generation, 3_000_000_000.0);
        $this->settleWalletCharge->handle($generation, '2000000000', '2500000000');

        $freshWallet = $user->fresh()->wallet;
        $this->assertSame('32.500000000', $freshWallet->balance_usd);
        $this->assertSame('0.000000000', $freshWallet->reserved_usd);
    }
}
