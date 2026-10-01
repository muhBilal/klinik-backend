<?php

namespace App\Enums;

/**
 * Status hamil/menyusui pasien perempuan (PRD PS-03) — kontraindikasi banyak treatment estetika (toksin botulinum, retinoid,
 * sebagian laser & peeling). Null di data = belum ditanyakan. Status bisa berubah, jadi selalu disertai tanggal dicatat.
 */
enum StatusKehamilan: string
{
    case Tidak = 'tidak';
    case Hamil = 'hamil';
    case Menyusui = 'menyusui';

    public function label(): string
    {
        return match ($this) {
            self::Tidak => 'Tidak hamil / menyusui',
            self::Hamil => 'Hamil',
            self::Menyusui => 'Menyusui',
        };
    }
}
