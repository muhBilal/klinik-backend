<?php

namespace App\Enums;

/** Status rencana perawatan gigi (PRD DG-02). */
enum StatusRencanaPerawatan: string
{
    /** Disusun dokter; item & estimasi biaya masih bisa diubah. */
    case Draf = 'draf';
    /** Estimasi disetujui pasien; perubahan harus lewat revisi (kembali ke draf). */
    case Disetujui = 'disetujui';
    /** Semua item selesai dikerjakan atau dibatalkan (otomatis). */
    case Selesai = 'selesai';
    case Dibatalkan = 'dibatalkan';

    /** Item rencana ini masih boleh dikerjakan di pemeriksaan. */
    public function bisaDikerjakan(): bool
    {
        return in_array($this, [self::Draf, self::Disetujui], true);
    }
}
