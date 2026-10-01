<?php

namespace App\Enums;

/** Status satu item rencana perawatan gigi (PRD DG-02). */
enum StatusItemRencana: string
{
    case Rencana = 'rencana';
    /** Dikerjakan di kunjungan yang sudah ditutup & ditandatangani. */
    case Selesai = 'selesai';
    case Batal = 'batal';
}
