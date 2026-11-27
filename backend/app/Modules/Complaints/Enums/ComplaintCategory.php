<?php

namespace App\Modules\Complaints\Enums;

enum ComplaintCategory: string
{
    case Bet = 'bet';
    case Transaction = 'transaction';
    case Account = 'account';
    case Other = 'other';
}
