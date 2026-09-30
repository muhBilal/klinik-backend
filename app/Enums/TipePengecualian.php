<?php

namespace App\Enums;

enum TipePengecualian: string
{
    case Cuti = 'cuti';
    case Tambahan = 'tambahan';

    public function label(): string
    {
        return match ($this) {
            self::Cuti => 'Cuti / libur',
            self::Tambahan => 'Jadwal tambahan',
        };
    }
}
