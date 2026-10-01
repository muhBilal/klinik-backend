<?php

namespace App\Models;

use App\Enums\KeparahanAlergi;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Alergi terstruktur pasien (PRD PS-03); `obat_id` → peringatan saat meresepkan (FR-02). */
#[Table('pasien_alergis')]
#[Fillable(['pasien_id', 'jenis', 'zat', 'obat_id', 'reaksi', 'keparahan', 'catatan', 'dicatat_oleh'])]
class PasienAlergi extends Model
{
    use Auditable, SoftDeletes;

    public const JENIS = ['obat', 'makanan', 'lingkungan', 'lainnya'];

    protected function casts(): array
    {
        return ['keparahan' => KeparahanAlergi::class];
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
