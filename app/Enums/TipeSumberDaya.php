<?php

namespace App\Enums;

enum TipeSumberDaya: string
{
    case Ruang = 'ruang';
    case Alat = 'alat';

    public function label(): string
    {
        return match ($this) {
            self::Ruang => 'Ruang',
            self::Alat => 'Alat',
        };
    }
}
