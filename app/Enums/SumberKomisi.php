<?php

namespace App\Enums;

/** Asal baris komisi: pembayaran tagihan (+) atau refund (−). */
enum SumberKomisi: string
{
    case Bayar = 'bayar';
    case Refund = 'refund';
}
