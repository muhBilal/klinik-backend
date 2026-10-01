<?php

namespace App\Enums;

/** Jenis potongan voucher & promo (PRD TR-06). */
enum JenisPotongan: string
{
    /** Persen dari nilai item yang memenuhi syarat, dibatasi `maks_potongan`. */
    case Persen = 'persen';
    /** Rupiah tetap, paling banyak sebesar nilai item yang memenuhi syarat. */
    case Nominal = 'nominal';
}
