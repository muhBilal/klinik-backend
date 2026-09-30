<?php

namespace App\Enums;

/**
 * Kode peran SISTEM (baris `perans` dengan `is_sistem = true`, dibuat oleh migration).
 * Hak akses TIDAK lagi ditentukan oleh kode peran, melainkan oleh izin (`App\Enums\Izin`) yang
 * dipegang peran. Enum ini hanya dipakai seeder/test untuk merujuk akun demo; peran kustom buatan
 * admin tidak ada di sini.
 */
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
