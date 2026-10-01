<?php

namespace App\Enums;

/**
 * Consent foto bertingkat (PRD FT-04): tingkat lebih tinggi mencakup yang di bawahnya.
 * klinis = hanya untuk rekam medis; edukasi = + pendidikan/presentasi ilmiah (tanpa identitas); marketing = + publikasi promosi.
 */
enum TingkatPersetujuanFoto: string
{
    case Klinis = 'klinis';
    case Edukasi = 'edukasi';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Klinis => 'Klinis saja',
            self::Edukasi => 'Klinis & edukasi',
            self::Marketing => 'Klinis, edukasi & marketing',
        };
    }

    public function keterangan(): string
    {
        return match ($this) {
            self::Klinis => 'Foto hanya dipakai untuk rekam medis dan evaluasi hasil perawatan pasien sendiri.',
            self::Edukasi => 'Foto juga boleh dipakai untuk pendidikan tenaga medis atau presentasi ilmiah tanpa menampilkan identitas pasien.',
            self::Marketing => 'Foto juga boleh dipakai untuk materi promosi klinik (media sosial, brosur, situs).',
        };
    }

    public function urutan(): int
    {
        return match ($this) {
            self::Klinis => 1,
            self::Edukasi => 2,
            self::Marketing => 3,
        };
    }

    /** Tingkat ini mengizinkan penggunaan `$penggunaan`. */
    public function mencakup(self $penggunaan): bool
    {
        return $this->urutan() >= $penggunaan->urutan();
    }
}
