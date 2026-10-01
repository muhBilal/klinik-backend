<?php

namespace App\Enums;

/**
 * Peran dalam satu tindakan yang menentukan komisi treatment (PRD KM-01: split dokter–terapis–asisten).
 * Sumber orangnya: dokter = dokter kunjungan, terapis = pelaksana tindakan (`petugas_id`), asisten = `asisten_id`.
 * Satu orang boleh menempati dua peran (mis. dokter yang mengerjakan sendiri) dan menerima keduanya bila treatment mengaturnya.
 */
enum PeranKomisi: string
{
    case Dokter = 'dokter';
    case Terapis = 'terapis';
    case Asisten = 'asisten';
    /** Baris koreksi manual di rekap (bonus/potongan), bukan dari komisi treatment. */
    case Penyesuaian = 'penyesuaian';

    public function label(): string
    {
        return match ($this) {
            self::Dokter => 'Dokter',
            self::Terapis => 'Terapis / pelaksana',
            self::Asisten => 'Asisten',
            self::Penyesuaian => 'Penyesuaian',
        };
    }

    /** Peran yang bisa diberi komisi di master treatment. @return list<self> */
    public static function perTreatment(): array
    {
        return [self::Dokter, self::Terapis, self::Asisten];
    }
}
