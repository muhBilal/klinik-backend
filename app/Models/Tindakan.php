<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('tindakans')]
#[Fillable(['kode', 'nama', 'tarif', 'is_active'])]
class Tindakan extends Model
{
    protected function casts(): array
    {
        return [
            'tarif' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
