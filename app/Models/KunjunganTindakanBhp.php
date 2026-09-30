<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemakaian BHP nyata pada satu tindakan kunjungan (PRD IN-02).
 * `jumlah_standar` disimpan sebagai pembanding untuk laporan pemakaian aktual vs standar (LP-04).
 */
#[Table('kunjungan_tindakan_bhps')]
#[Fillable(['kunjungan_tindakan_id', 'obat_id', 'batch_id', 'jumlah_standar', 'jumlah', 'stok_dipotong', 'dicatat_oleh'])]
class KunjunganTindakanBhp extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'jumlah_standar' => 'float',
            'jumlah' => 'float',
            'stok_dipotong' => 'boolean',
        ];
    }

    public function kunjunganTindakan(): BelongsTo
    {
        return $this->belongsTo(KunjunganTindakan::class);
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StokBatch::class, 'batch_id')->withoutGlobalScope('cabang');
    }

    public function auditLabel(): ?string
    {
        return Obat::withTrashed()->whereKey($this->obat_id)->value('kode');
    }
}
