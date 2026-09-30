<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kategori treatment (injeksi, laser, facial, perawatan gigi, ...). Master pusat.
 */
#[Table('kategori_tindakans')]
#[Fillable(['nama', 'deskripsi', 'is_active'])]
class KategoriTindakan extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tindakans(): HasMany
    {
        return $this->hasMany(Tindakan::class, 'kategori_id');
    }

    public function auditLabel(): ?string
    {
        return $this->nama;
    }
}
