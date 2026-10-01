<?php

namespace App\Enums;

/** Kategori alergi pasien (PRD PS-03). Padanan FHIR AllergyIntolerance.category untuk SATUSEHAT: medication / food / environment. */
enum KategoriAlergi: string
{
    case Obat = 'obat';
    case Makanan = 'makanan';
    case Lingkungan = 'lingkungan';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Obat => 'Obat',
            self::Makanan => 'Makanan',
            self::Lingkungan => 'Lingkungan (debu, lateks, dll.)',
            self::Lainnya => 'Lainnya',
        };
    }
}
