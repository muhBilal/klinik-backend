<?php

namespace App\Enums;

enum StatusConsent: string
{
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
    /** Pasien menarik persetujuan sebelum tindakan dilakukan. */
    case Dicabut = 'dicabut';

    public function label(): string
    {
        return match ($this) {
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::Dicabut => 'Dicabut',
        };
    }
}
