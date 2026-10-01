<?php

namespace App\Enums;

/** Asal nilai yang dikomisikan (PRD KM-01). Penjualan produk & paket (KM-02) menyusul di Fase 2. */
enum SumberKomisi: string
{
    /** Tindakan kunjungan (termasuk sesi paket, dengan dasar nilai per sesi). */
    case Tindakan = 'tindakan';
    /** Jasa konsultasi dokter: item tagihan konsultasi (treatment jasa konsultasi poli), komisi peran dokter treatment itu. */
    case Konsultasi = 'konsultasi';
    case Penyesuaian = 'penyesuaian';
}
