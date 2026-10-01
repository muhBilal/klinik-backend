<?php

namespace App\Models;

use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Antrean & status kirim satu kunjungan ke SATUSEHAT (PRD v2 SS-05). */
#[Table('satusehat_kirims')]
#[Fillable(['kunjungan_id', 'cabang_id', 'status', 'percobaan', 'encounter_id', 'hasil', 'error', 'terakhir_dicoba_at', 'terkirim_at'])]
class SatuSehatKirim extends Model
{
    use DalamCabang;

    public const MENUNGGU = 'menunggu';

    public const TERKIRIM = 'terkirim';

    public const GAGAL = 'gagal';

    protected function casts(): array
    {
        return [
            'hasil' => 'array',
            'percobaan' => 'integer',
            'terakhir_dicoba_at' => 'datetime',
            'terkirim_at' => 'datetime',
        ];
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }
}
