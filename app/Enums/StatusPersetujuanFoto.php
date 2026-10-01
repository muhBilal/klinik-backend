<?php

namespace App\Enums;

enum StatusPersetujuanFoto: string
{
    case Berlaku = 'berlaku';
    /** Digantikan persetujuan baru (mis. pasien menaikkan/menurunkan tingkat). */
    case Diganti = 'diganti';
    /** Ditarik pasien; foto baru tidak boleh diambil sampai pasien menyetujui lagi. */
    case Dicabut = 'dicabut';
}
