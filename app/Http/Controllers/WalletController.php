<?php

namespace App\Http\Controllers;

use App\Actions\Wallet\TopUpWallet;
use App\Http\Requests\TopUpWalletRequest;
use App\Http\Resources\WalletTransactionResource;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * Return a paginated list (10 per page) of the authenticated customer's wallet transactions.
     *
     * Ordered newest first and serialized using {@see WalletTransactionResource}.
     */
    public function index(Request $request): JsonResponse
    {
        $transactions = $request->user()
            ->walletTransactions()
            ->orderByDesc('id')
            ->paginate(10);

        return WalletTransactionResource::collection($transactions)->response();
    }

    /**
     * Top up the authenticated customer's wallet balance from a validated USD amount.
     *
     * Converts the amount to nanodollars via {@see Money::usdToNano()}, records the transaction
     * using {@see TopUpWallet}, and redirects back (falling back to the dashboard) with a flash notice.
     */
    public function store(TopUpWalletRequest $request, TopUpWallet $topUpWallet): RedirectResponse
    {
        $amountNano = Money::usdToNano((string) $request->validated('amount_usd'));

        $topUpWallet->handle($request->user(), $amountNano);

        return back(fallback: route('dashboard'))
            ->with('success', 'Wallet topped up successfully.');
    }
}
