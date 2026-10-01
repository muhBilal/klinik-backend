<?php

namespace App\Enums;

/** Bentuk nilai komisi treatment (PRD KM-01). */
enum JenisKomisi: string
{
    /** Persen dari nilai dasar (bruto/neto sesuai pengaturan `komisi.dasar`). */
    case Persen = 'persen';
    /** Rupiah tetap per unit (dikali jumlah tindakan). */
    case Nominal = 'nominal';
}
