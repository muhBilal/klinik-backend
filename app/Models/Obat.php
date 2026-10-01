<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('obats')]
#[Fillable(['kode', 'nama', 'satuan', 'jenis', 'no_bpom', 'fraksional', 'jam_pakai_setelah_buka', 'harga', 'stok_minimum', 'is_active'])]
class Obat extends Model
{
    /** Jenis produk (AD-06): skincare/kosmetik yang dijual wajib bernomor notifikasi BPOM. */
    public const JENIS = ['obat', 'skincare', 'bhp', 'alkes'];

    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'harga' => 'integer',
            'stok' => 'float',
            'stok_minimum' => 'integer',
            'fraksional' => 'boolean',
            'jam_pakai_setelah_buka' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function mutasis(): HasMany
    {
        return $this->hasMany(StokMutasi::class)->latest('id');
    }

    /** Stok per cabang per batch (IN-01). `stok` pada obat adalah totalnya. */
    public function batches(): HasMany
    {
        return $this->hasMany(StokBatch::class)->withoutGlobalScope('cabang');
    }

    /** Stok di satu cabang saja. */
    public function stokDi(?int $cabangId): float
    {
        return (float) $this->batches()
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->tersedia()
            ->sum('jumlah');
    }

    public function scopeStokMenipis(Builder $query): void
    {
        $query->whereColumn('stok', '<=', 'stok_minimum');
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }

    /** Perubahan stok sudah tercatat lengkap di kartu stok (stok_mutasis). */
    public function auditAbaikan(): array
    {
        return ['stok'];
    }
}
