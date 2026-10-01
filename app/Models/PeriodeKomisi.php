<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Status periode komisi satu cabang (PRD KM-03). `disetujui` = terkunci. */
#[Table('periode_komisis')]
#[Fillable(['cabang_id', 'periode', 'status', 'disetujui_oleh', 'disetujui_at', 'catatan'])]
class PeriodeKomisi extends Model
{
    use Auditable, DalamCabang;

    public const DISETUJUI = 'disetujui';

    protected function casts(): array
    {
        return ['disetujui_at' => 'datetime'];
    }

    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh')->withTrashed();
    }

    public function auditLabel(): ?string
    {
        return "Komisi {$this->periode}";
    }
}
