<?php

namespace App\Enums;

/**
 * Spesialisasi poli (PRD bagian 6). Menentukan modul spesialisasi yang tampil di pemeriksaan, mis. odontogram hanya
 * untuk poli `gigi` — klinik tanpa poli gigi tidak pernah melihat odontogram.
 */
enum Spesialisasi: string
{
    case Umum = 'umum';
    case Gigi = 'gigi';
    case Kulit = 'kulit';
    case Estetika = 'estetika';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Umum => 'Umum',
            self::Gigi => 'Kedokteran gigi',
            self::Kulit => 'Dermatologi & venereologi',
            self::Estetika => 'Estetika medis',
            self::Lainnya => 'Lainnya',
        };
    }
}
