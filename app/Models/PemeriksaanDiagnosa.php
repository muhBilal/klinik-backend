<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('pemeriksaan_diagnosas')]
#[Fillable(['pemeriksaan_id', 'icd10_id', 'jenis'])]
class PemeriksaanDiagnosa extends Model
{
    use Auditable;

    public function icd10(): BelongsTo
    {
        return $this->belongsTo(Icd10::class);
    }

    public function auditPasienId(): ?int
    {
        return app(AuditService::class)->pasienDariKunjungan(
            Pemeriksaan::whereKey($this->pemeriksaan_id)->value('kunjungan_id'),
        );
    }
}
