<?php

namespace App\Enums;

/** Consent UU PDP terpisah (PRD PS-04): pemrosesan data untuk pelayanan vs. opt-in pemasaran. */
enum JenisPersetujuanData: string
{
    case Pemrosesan = 'pemrosesan';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Pemrosesan => 'Pemrosesan data pribadi & kesehatan',
            self::Marketing => 'Informasi promosi & pemasaran',
        };
    }
}
