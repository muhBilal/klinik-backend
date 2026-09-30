<?php

namespace App\Enums;

enum KategoriBerkas: string
{
    case FotoKlinis = 'foto_klinis';
    case InformedConsent = 'informed_consent';
    case Radiologi = 'radiologi';
    case HasilPenunjang = 'hasil_penunjang';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::FotoKlinis => 'Foto klinis',
            self::InformedConsent => 'Informed consent',
            self::Radiologi => 'Radiologi',
            self::HasilPenunjang => 'Hasil penunjang',
            self::Lainnya => 'Lainnya',
        };
    }
}
