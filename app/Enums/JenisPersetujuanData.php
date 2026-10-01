<?php

namespace App\Enums;

/**
 * Jenis persetujuan data pribadi (PRD PS-04, UU No. 27/2022 tentang Pelindungan Data Pribadi). Pemrosesan data untuk pelayanan
 * dan opt-in marketing dicatat terpisah: masing-masing bisa diberikan & dicabut sendiri.
 */
enum JenisPersetujuanData: string
{
    case Pemrosesan = 'pemrosesan';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Pemrosesan => 'Pemrosesan data pribadi & kesehatan',
            self::Marketing => 'Informasi promosi (opt-in marketing)',
        };
    }
}
