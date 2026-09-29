<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Pendaftaran = 'pendaftaran';
    case Perawat = 'perawat';
    case Dokter = 'dokter';
    case Apoteker = 'apoteker';
    case Kasir = 'kasir';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Pendaftaran => 'Petugas Pendaftaran',
            self::Perawat => 'Perawat',
            self::Dokter => 'Dokter',
            self::Apoteker => 'Apoteker',
            self::Kasir => 'Kasir',
        };
    }
}
