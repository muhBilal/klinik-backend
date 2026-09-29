<?php

namespace App\Enums;

enum StatusKunjungan: string
{
    case Menunggu = 'menunggu';
    case Diperiksa = 'diperiksa';
    case MenungguPembayaran = 'menunggu_pembayaran';
    case Selesai = 'selesai';
    case Batal = 'batal';
}
