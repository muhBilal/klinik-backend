<?php

namespace App\Models;

use App\Enums\PeranKomisi;
use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Petugas tambahan satu tindakan kunjungan (asisten, terapis kedua) — PRD AN-03. */
#[Table('kunjungan_tindakan_petugas')]
#[Fillable(['kunjungan_tindakan_id', 'user_id', 'peran'])]
class KunjunganTindakanPetugas extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['peran' => PeranKomisi::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function kunjunganTindakan(): BelongsTo
    {
        return $this->belongsTo(KunjunganTindakan::class);
    }

    public function auditPasienId(): ?int
    {
        return app(AuditService::class)->pasienDariKunjungan($this->kunjunganTindakan?->kunjungan_id);
    }
}
