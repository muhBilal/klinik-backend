<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('icd10s')]
#[Fillable(['kode', 'nama'])]
class Icd10 extends Model
{
    use Auditable;

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
