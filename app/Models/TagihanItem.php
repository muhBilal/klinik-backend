<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('tagihan_items')]
#[Fillable(['tagihan_id', 'kategori', 'tindakan_id', 'paket_id', 'deskripsi', 'jumlah', 'harga', 'subtotal', 'neto'])]
class TagihanItem extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'harga' => 'integer',
            'subtotal' => 'integer',
            'neto' => 'integer',
            'tindakan_id' => 'integer',
            'paket_id' => 'integer',
        ];
    }
}
