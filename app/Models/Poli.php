<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('polis')]
#[Fillable(['kode', 'nama', 'tarif_konsultasi', 'is_active'])]
class Poli extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'tarif_konsultasi' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function dokters(): HasMany
    {
        return $this->hasMany(User::class)->dokter();
    }

    /** Kunjungan di cabang aktif (global scope cabang). */
    public function kunjungans(): HasMany
    {
        return $this->hasMany(Kunjungan::class);
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
