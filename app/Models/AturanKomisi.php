<?php

namespace App\Models;

use App\Enums\JenisPotongan;
use App\Enums\PeranKomisi;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aturan komisi (PRD KM-01): persen dari dasar atau nominal per tindakan, untuk satu peran.
 * Cakupan: treatment tertentu, kategori treatment, atau semua (keduanya null); opsional hanya satu cabang.
 */
#[Table('aturan_komisis')]
#[Fillable(['tindakan_id', 'kategori_id', 'cabang_id', 'peran', 'jenis', 'nilai', 'is_active', 'keterangan'])]
class AturanKomisi extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'peran' => PeranKomisi::class,
            'jenis' => JenisPotongan::class,
            'nilai' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriTindakan::class, 'kategori_id')->withTrashed();
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class)->withTrashed();
    }

    /** Skor kecocokan: treatment (4) > kategori (2) > semua; khusus cabang (+1). Makin tinggi makin spesifik. */
    public function skor(): int
    {
        return ($this->tindakan_id ? 4 : 0) + ($this->kategori_id ? 2 : 0) + ($this->cabang_id ? 1 : 0);
    }

    public function auditLabel(): ?string
    {
        return "{$this->peran->value} {$this->jenis->value} {$this->nilai}";
    }
}
