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
#[Fillable(['kode', 'nama', 'satuan', 'harga', 'stok_minimum', 'is_active'])]
class Obat extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'harga' => 'integer',
            'stok' => 'integer',
            'stok_minimum' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function mutasis(): HasMany
    {
        return $this->hasMany(StokMutasi::class)->latest('id');
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
