<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case TopUp = 'topup';
    case Hold = 'hold';
    case Release = 'release';
    case Charge = 'charge';
}
