<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Komponen satu racikan (PRD FR-01): obat + jumlah total untuk seluruh racikan, harga per satuan di-snapshot. */
#[Table('resep_item_komponens')]
#[Fillable(['resep_item_id', 'obat_id', 'jumlah', 'harga'])]
class ResepItemKomponen extends Model
{
    protected function casts(): array
    {
        return ['jumlah' => 'float', 'harga' => 'integer'];
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class)->withTrashed();
    }
}
