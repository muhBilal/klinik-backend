<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu titik injeksi pada face chart (PRD ES-01). `x`, `y` = posisi relatif 0..1 pada diagram wajah `tampilan`.
 */
#[Table('catatan_tindakan_titiks')]
#[Fillable(['catatan_tindakan_id', 'tampilan', 'x', 'y', 'area', 'obat_id', 'batch_id', 'jumlah', 'satuan', 'kedalaman', 'alat', 'catatan'])]
class CatatanTindakanTitik extends Model
{
    use Auditable;

    public const TAMPILAN = ['depan', 'kiri', 'kanan'];

    public const SATUAN = ['U', 'ml', 'mg', 'mcg'];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'jumlah' => 'float',
        ];
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
        return $this->area;
    }
}
