<?php

namespace App\Enums;

/** Tipe kulit Fitzpatrick (PRD PS-03): acuan parameter laser/peeling dan risiko hiperpigmentasi pasca-inflamasi. */
enum TipeKulitFitzpatrick: string
{
    case I = 'I';
    case II = 'II';
    case III = 'III';
    case IV = 'IV';
    case V = 'V';
    case VI = 'VI';

    public function keterangan(): string
    {
        return match ($this) {
            self::I => 'Selalu terbakar, tidak pernah menggelap',
            self::II => 'Mudah terbakar, sedikit menggelap',
            self::III => 'Kadang terbakar, menggelap bertahap',
            self::IV => 'Jarang terbakar, mudah menggelap',
            self::V => 'Sangat jarang terbakar, sangat mudah menggelap',
            self::VI => 'Tidak pernah terbakar, kulit sangat gelap',
        };
    }
}
