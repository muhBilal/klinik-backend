<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('tindakans')]
#[Fillable(['kode', 'nama', 'tarif', 'is_active'])]
class Tindakan extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'tarif' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
