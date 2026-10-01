<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Template SOAP per spesialisasi (poli) dan per treatment (PRD RM-01, DR-03). Hanya mengisi form pemeriksaan;
 * `akses_terbatas` membuat kunjungan yang memakainya berakses terbatas (mis. template kasus IMS).
 */
#[Table('template_soaps')]
#[Fillable(['nama', 'poli_id', 'tindakan_id', 'subjektif', 'objektif', 'asesmen', 'plan', 'icd10_ids', 'akses_terbatas', 'is_active'])]
class TemplateSoap extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'icd10_ids' => 'array',
            'akses_terbatas' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function poli(): BelongsTo
    {
        return $this->belongsTo(Poli::class)->withTrashed();
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    public function auditLabel(): ?string
    {
        return $this->nama;
    }
}
