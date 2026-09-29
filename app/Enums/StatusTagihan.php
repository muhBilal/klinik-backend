<?php

namespace App\Enums;

enum StatusTagihan: string
{
    case BelumBayar = 'belum_bayar';
    case Lunas = 'lunas';
    case Batal = 'batal';
}
