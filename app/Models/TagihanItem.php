<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('tagihan_items')]
#[Fillable(['tagihan_id', 'kategori', 'deskripsi', 'jumlah', 'harga', 'subtotal'])]
class TagihanItem extends Model
{
    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'harga' => 'integer',
            'subtotal' => 'integer',
        ];
    }
}
