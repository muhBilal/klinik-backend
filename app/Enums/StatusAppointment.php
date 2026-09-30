<?php

namespace App\Enums;

/**
 * Siklus hidup booking (PRD BK-01, AN-01). `Hadir` = sudah di-check-in menjadi kunjungan.
 */
enum StatusAppointment: string
{
    case Dijadwalkan = 'dijadwalkan';
    case Dikonfirmasi = 'dikonfirmasi';
    case Hadir = 'hadir';
    case Batal = 'batal';
    case TidakHadir = 'tidak_hadir';

    public function label(): string
    {
        return match ($this) {
            self::Dijadwalkan => 'Dijadwalkan',
            self::Dikonfirmasi => 'Dikonfirmasi',
            self::Hadir => 'Hadir',
            self::Batal => 'Batal',
            self::TidakHadir => 'Tidak hadir',
        };
    }

    /** Booking yang masih memesan slot (dihitung saat cek bentrok). */
    public static function aktif(): array
    {
        return [self::Dijadwalkan, self::Dikonfirmasi, self::Hadir];
    }
}
