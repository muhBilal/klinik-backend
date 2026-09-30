<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('resep_items')]
#[Fillable(['resep_id', 'obat_id', 'jumlah', 'aturan_pakai', 'harga'])]
class ResepItem extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'harga' => 'integer',
        ];
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class)->withTrashed();
    }

    public function auditPasienId(): ?int
    {
        return app(AuditService::class)->pasienDariKunjungan(
            Resep::withoutGlobalScope('cabang')->whereKey($this->resep_id)->value('kunjungan_id'),
        );
    }
}
