<?php

namespace App\Enums;

/**
 * Bentuk catatan tindakan (PRD RM-05). Ditentukan per treatment di katalog.
 */
enum JenisCatatanTindakan: string
{
    case Umum = 'umum';
    /** Face chart injeksi: titik, dosis, produk & batch, jarum/kanula (ES-01). */
    case Injeksi = 'injeksi';
    /** Parameter laser / energy device (ES-02). */
    case Energi = 'energi';

    public function label(): string
    {
        return match ($this) {
            self::Umum => 'Catatan umum',
            self::Injeksi => 'Face chart injeksi',
            self::Energi => 'Parameter laser / energy device',
        };
    }
}
