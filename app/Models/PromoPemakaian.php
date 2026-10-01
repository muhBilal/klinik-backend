<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pemakaian kode promo pada tagihan yang lunas (TR-06). Refund tagihan mengisi `dibatalkan_at` → kuota kembali. */
#[Table('promo_pemakaians')]
#[Fillable(['promo_id', 'tagihan_id', 'pasien_id', 'cabang_id', 'potongan', 'dipakai_at', 'dibatalkan_at'])]
class PromoPemakaian extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'potongan' => 'integer',
            'dipakai_at' => 'datetime',
            'dibatalkan_at' => 'datetime',
        ];
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class)->withTrashed();
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class)->withoutGlobalScope('cabang');
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }
}
