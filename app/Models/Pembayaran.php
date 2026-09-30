<?php

namespace App\Models;

use App\Enums\MetodeBayar;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris pembayaran tagihan (PRD BL-03 split payment). Refund membatalkan baris,
 * bukan menambah baris bernilai negatif, sehingga rekap shift tetap sederhana (BL-06).
 */
#[Table('pembayarans')]
#[Fillable([
    'tagihan_id', 'shift_id', 'metode', 'jumlah', 'referensi', 'kasir_id', 'dibayar_at',
    'dikembalikan_at', 'dikembalikan_oleh', 'alasan_refund',
])]
class Pembayaran extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'metode' => MetodeBayar::class,
            'jumlah' => 'integer',
            'dibayar_at' => 'datetime',
            'dikembalikan_at' => 'datetime',
        ];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class);
    }

    public function kasir(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kasir_id')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftKas::class, 'shift_id')->withoutGlobalScope('cabang');
    }

    /** Pembayaran yang masih berlaku (belum di-refund). */
    public function scopeBerlaku(Builder $query): void
    {
        $query->whereNull('dikembalikan_at');
    }

    public function auditPasienId(): ?int
    {
        return $this->tagihan?->pasienId();
    }
}
