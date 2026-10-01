<?php

namespace App\Enums;

/** Hubungan penanda tangan informed consent dengan pasien. */
enum HubunganPenandatangan: string
{
    case Pasien = 'pasien';
    case OrangTua = 'orang_tua';
    case SuamiIstri = 'suami_istri';
    case Anak = 'anak';
    case Saudara = 'saudara';
    case Wali = 'wali';

    public function label(): string
    {
        return match ($this) {
            self::Pasien => 'Pasien sendiri',
            self::OrangTua => 'Orang tua',
            self::SuamiIstri => 'Suami / istri',
            self::Anak => 'Anak',
            self::Saudara => 'Saudara kandung',
            self::Wali => 'Wali',
        };
    }
}
