<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kode tindakan/prosedur ICD-9-CM (PRD RM-02). Dipakai sebagai kode default treatment dan per tindakan kunjungan.
 */
#[Table('icd9cms')]
#[Fillable(['kode', 'nama'])]
class Icd9cm extends Model
{
    use Auditable;

    public function favorits(): HasMany
    {
        return $this->hasMany(KodeFavorit::class, 'kode_id')->where('jenis', KodeFavorit::ICD9CM);
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
