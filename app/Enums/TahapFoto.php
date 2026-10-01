<?php

namespace App\Enums;

/** Tahap foto klinis relatif terhadap tindakan (before-after, PRD FT-02). */
enum TahapFoto: string
{
    case Sebelum = 'sebelum';
    case Sesudah = 'sesudah';
    case Kontrol = 'kontrol';

    public function label(): string
    {
        return match ($this) {
            self::Sebelum => 'Sebelum',
            self::Sesudah => 'Sesudah',
            self::Kontrol => 'Kontrol',
        };
    }
}
