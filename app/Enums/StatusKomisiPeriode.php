<?php

namespace App\Enums;

/** Status rekap komisi (PRD KM-03: dikunci setelah disetujui). */
enum StatusKomisiPeriode: string
{
    /** Bisa dihitung ulang, diberi penyesuaian, atau dihapus. */
    case Draf = 'draf';
    /** Terkunci: baris tidak berubah lagi walau data sumber berubah (mis. refund setelahnya). */
    case Disetujui = 'disetujui';
}
