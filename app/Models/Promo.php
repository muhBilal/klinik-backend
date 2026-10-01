<?php

namespace App\Models;

use App\Enums\JenisPotongan;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Voucher & kode promo (PRD TR-06). Satu kode bisa dipakai banyak tagihan sampai `kuota`; voucher sekali pakai = kuota 1.
 * Aturan & perhitungan potongan: `PromoService`.
 */
#[Table('promos')]
#[Fillable([
    'kode', 'nama', 'deskripsi', 'jenis', 'nilai', 'maks_potongan', 'min_transaksi', 'mulai', 'berakhir', 'kuota', 'kuota_per_pasien',
    'cabang_ids', 'tindakan_ids', 'paket_ids', 'is_active', 'created_by',
])]
class Promo extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'jenis' => JenisPotongan::class,
            'nilai' => 'integer',
            'maks_potongan' => 'integer',
            'min_transaksi' => 'integer',
            'mulai' => 'date:Y-m-d',
            'berakhir' => 'date:Y-m-d',
            'kuota' => 'integer',
            'kuota_per_pasien' => 'integer',
            'cabang_ids' => 'array',
            'tindakan_ids' => 'array',
            'paket_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function pemakaians(): HasMany
    {
        return $this->hasMany(PromoPemakaian::class);
    }

    /** Pemakaian yang masih dihitung kuota (tagihan tidak direfund). */
    public function pemakaianBerlaku(): HasMany
    {
        return $this->hasMany(PromoPemakaian::class)->whereNull('dibatalkan_at');
    }

    /** Berlaku untuk seluruh item tagihan (tidak dibatasi treatment / paket tertentu). */
    public function semuaItem(): bool
    {
        return empty($this->tindakan_ids) && empty($this->paket_ids);
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
