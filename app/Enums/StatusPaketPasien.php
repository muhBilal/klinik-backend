<?php

namespace App\Enums;

/**
 * Status paket milik pasien (PRD TR-02). Yang disimpan hanya peristiwa; `habis` & `kedaluwarsa` dihitung dari sisa sesi &
 * tanggal (`PaketPasien::statusEfektif()`), sehingga tidak butuh scheduler.
 */
enum StatusPaketPasien: string
{
    /** Tagihan penjualan belum dibayar. */
    case MenungguBayar = 'menunggu_bayar';
    case Aktif = 'aktif';
    /** Tagihan penjualan dibatalkan sebelum dibayar. */
    case Dibatalkan = 'dibatalkan';
    /** Dana dikembalikan (penuh lewat refund tagihan, atau sisa prorata); sisa sesi hangus. */
    case Direfund = 'direfund';
    /** Sisa sesi dialihkan ke paket baru milik pasien lain. */
    case Dialihkan = 'dialihkan';

    // Status turunan (tidak disimpan)
    public const HABIS = 'habis';

    public const KEDALUWARSA = 'kedaluwarsa';
}
