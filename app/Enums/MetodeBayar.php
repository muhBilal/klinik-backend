<?php

namespace App\Enums;

enum MetodeBayar: string
{
    case Tunai = 'tunai';
    case Debit = 'debit';
    case Qris = 'qris';
    case Transfer = 'transfer';
    case Penjamin = 'penjamin';
}
