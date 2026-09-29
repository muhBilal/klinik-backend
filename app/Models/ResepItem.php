<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('resep_items')]
#[Fillable(['resep_id', 'obat_id', 'jumlah', 'aturan_pakai', 'harga'])]
class ResepItem extends Model
{
    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'harga' => 'integer',
        ];
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class);
    }
}
