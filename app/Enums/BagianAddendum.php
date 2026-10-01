<?php

namespace App\Enums;

/** Bagian rekam medis yang dikoreksi lewat addendum (PRD RM-07). */
enum BagianAddendum: string
{
    case Subjektif = 'subjektif';
    case Objektif = 'objektif';
    case Asesmen = 'asesmen';
    case Plan = 'plan';
    case Diagnosa = 'diagnosa';
    case Tindakan = 'tindakan';
    case Resep = 'resep';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Subjektif => 'Subjektif',
            self::Objektif => 'Objektif',
            self::Asesmen => 'Asesmen',
            self::Plan => 'Plan',
            self::Diagnosa => 'Diagnosa',
            self::Tindakan => 'Tindakan',
            self::Resep => 'Resep',
            self::Lainnya => 'Lainnya',
        };
    }
}
