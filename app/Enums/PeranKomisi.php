<?php

namespace App\Enums;

/** Peran petugas dalam satu tindakan, dasar pembagian komisi (PRD KM-01, AN-03). */
enum PeranKomisi: string
{
    case Dokter = 'dokter';
    case Terapis = 'terapis';
    case Asisten = 'asisten';

    public function label(): string
    {
        return match ($this) {
            self::Dokter => 'Dokter',
            self::Terapis => 'Terapis / perawat',
            self::Asisten => 'Asisten',
        };
    }
}
