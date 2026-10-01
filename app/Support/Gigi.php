<?php

namespace App\Support;

/**
 * Nomor gigi notasi FDI (dua digit: kuadran + urutan dari garis tengah) dan permukaan gigi (PRD DG-01).
 *
 * - Gigi tetap: kuadran 1–4, urutan 1–8 (11–18, 21–28, 31–38, 41–48).
 * - Gigi sulung: kuadran 5–8, urutan 1–5 (51–55, 61–65, 71–75, 81–85).
 * - Permukaan: M (mesial), O (oklusal; insisal pada gigi anterior), D (distal), B (bukal; labial pada anterior),
 *   L (lingual; palatal pada rahang atas). Kode disimpan tetap M/O/D/B/L, label menyesuaikan jenis gigi.
 */
class Gigi
{
    public const PERMUKAAN = ['M', 'O', 'D', 'B', 'L'];

    public static function valid(mixed $nomor): bool
    {
        if (! is_int($nomor) && ! (is_string($nomor) && ctype_digit($nomor))) {
            return false;
        }

        $nomor = (int) $nomor;
        $kuadran = intdiv($nomor, 10);
        $urutan = $nomor % 10;

        return match (true) {
            $kuadran >= 1 && $kuadran <= 4 => $urutan >= 1 && $urutan <= 8,
            $kuadran >= 5 && $kuadran <= 8 => $urutan >= 1 && $urutan <= 5,
            default => false,
        };
    }

    public static function sulung(int $nomor): bool
    {
        return intdiv($nomor, 10) >= 5;
    }

    /** Gigi anterior (insisivus & kaninus): permukaan O berlabel insisal. */
    public static function anterior(int $nomor): bool
    {
        return $nomor % 10 <= 3;
    }

    public static function rahangAtas(int $nomor): bool
    {
        return in_array(intdiv($nomor, 10), [1, 2, 5, 6], true);
    }

    /**
     * Normalkan daftar permukaan ke urutan baku M-O-D-B-L tanpa duplikat, mis. ["O", "M"] / "om" → "MO".
     * Null bila kosong.
     */
    public static function normalPermukaan(string|array|null $permukaan): ?string
    {
        $huruf = is_array($permukaan) ? $permukaan : str_split(strtoupper((string) $permukaan));
        $hasil = implode('', array_values(array_intersect(self::PERMUKAAN, array_map('strtoupper', $huruf))));

        return $hasil === '' ? null : $hasil;
    }

    /** Semua huruf valid (M/O/D/B/L) & tidak berulang. */
    public static function permukaanValid(string $permukaan): bool
    {
        $huruf = str_split(strtoupper($permukaan));

        return $huruf !== [] && count($huruf) === count(array_unique($huruf)) && ! array_diff($huruf, self::PERMUKAAN);
    }

    public static function labelPermukaan(int $gigi, string $kode): string
    {
        return match ($kode) {
            'M' => 'Mesial',
            'D' => 'Distal',
            'O' => self::anterior($gigi) ? 'Insisal' : 'Oklusal',
            'B' => self::anterior($gigi) ? 'Labial' : 'Bukal',
            'L' => self::rahangAtas($gigi) ? 'Palatal' : 'Lingual',
            default => $kode,
        };
    }

    /** Teks ringkas untuk tagihan & ringkasan RME, mis. "gigi 16 (MO)". */
    public static function format(?int $gigi, ?string $permukaan = null): ?string
    {
        if (! $gigi) {
            return null;
        }

        return 'gigi '.$gigi.($permukaan ? " ({$permukaan})" : '');
    }
}
