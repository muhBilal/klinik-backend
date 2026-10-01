<?php

namespace App\Models;

use App\Enums\JenisPotongan;
use App\Enums\PeranKomisi;
use App\Enums\SumberKomisi;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kejadian komisi untuk satu petugas (PRD KM-01/03): `bayar` (+) saat tagihan lunas, `refund` (−) saat direfund.
 * Aturan & dasar di-snapshot. Baris pada periode yang sudah disetujui tidak pernah diubah.
 */
#[Table('komisis')]
#[Fillable([
    'cabang_id', 'periode', 'user_id', 'peran', 'sumber', 'tagihan_id', 'tagihan_item_id', 'kunjungan_tindakan_id',
    'tindakan_id', 'aturan_id', 'dasar', 'jenis', 'nilai_aturan', 'dibagi', 'jumlah', 'keterangan',
])]
class Komisi extends Model
{
    use DalamCabang;

    protected function casts(): array
    {
        return [
            'peran' => PeranKomisi::class,
            'sumber' => SumberKomisi::class,
            'jenis' => JenisPotongan::class,
            'dasar' => 'integer',
            'nilai_aturan' => 'float',
            'dibagi' => 'integer',
            'jumlah' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class)->withoutGlobalScope('cabang');
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    public function kunjunganTindakan(): BelongsTo
    {
        return $this->belongsTo(KunjunganTindakan::class);
    }
}
