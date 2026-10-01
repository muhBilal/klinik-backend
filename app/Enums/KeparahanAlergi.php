<?php

namespace App\Enums;

/** Tingkat keparahan reaksi alergi (FHIR reaction.severity: mild / moderate / severe). */
enum KeparahanAlergi: string
{
    case Ringan = 'ringan';
    case Sedang = 'sedang';
    case Berat = 'berat';
}
