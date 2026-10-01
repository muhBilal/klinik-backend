<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Katalog paket multi-sesi (PRD TR-02), mis. "Laser toning 6x + facial 3x". Mengubah katalog tidak mengubah paket yang
 * sudah dibeli (isi & harga di-snapshot ke `paket_pasiens`).
 */
#[Table('pakets')]
#[Fillable(['kode', 'nama', 'deskripsi', 'harga', 'masa_berlaku_hari', 'lintas_cabang', 'is_active'])]
class Paket extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'harga' => 'integer',
            'masa_berlaku_hari' => 'integer',
            'lintas_cabang' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaketItem::class)->orderBy('id');
    }

    public function terjual(): HasMany
    {
        return $this->hasMany(PaketPasien::class);
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
