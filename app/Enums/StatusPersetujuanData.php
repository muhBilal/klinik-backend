<?php

namespace App\Enums;

enum StatusPersetujuanData: string
{
    case Berlaku = 'berlaku';
    /** Digantikan persetujuan yang ditandatangani kemudian. */
    case Diganti = 'diganti';
    /** Ditarik pasien (hak subjek data); untuk marketing juga saat formulir baru menyatakan tidak bersedia. */
    case Dicabut = 'dicabut';
}
