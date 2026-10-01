<?php

namespace App\Models;

use App\Enums\KategoriAlergi;
use App\Enums\KeparahanAlergi;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu alergi pasien (PRD PS-03). Alergi obat bisa ditautkan ke master obat agar resep yang memuat obat itu diberi peringatan.
 */
#[Table('pasien_alergis')]
#[Fillable(['pasien_id', 'kategori', 'zat', 'obat_id', 'reaksi', 'keparahan', 'dicatat_oleh'])]
class PasienAlergi extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'kategori' => KategoriAlergi::class,
            'keparahan' => KeparahanAlergi::class,
            'obat_id' => 'integer',
        ];
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class)->withTrashed();
    }

    public function auditLabel(): ?string
    {
        return "Alergi {$this->zat}";
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }
}
