<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Naskah informed consent (PRD RM-03). Placeholder diisi saat ditandatangani, lalu hasilnya di-snapshot
 * ke `informed_consents.isi` — mengubah template tidak mengubah consent yang sudah ditandatangani.
 */
#[Table('template_consents')]
#[Fillable(['nama', 'isi', 'is_active'])]
class TemplateConsent extends Model
{
    use Auditable, SoftDeletes;

    /** Placeholder yang dikenali => keterangan (ditampilkan di form master). */
    public const PLACEHOLDER = [
        '{nama_pasien}' => 'Nama pasien',
        '{no_rm}' => 'Nomor rekam medis',
        '{tanggal_lahir}' => 'Tanggal lahir pasien',
        '{tindakan}' => 'Nama tindakan/treatment',
        '{dokter}' => 'Dokter pemberi penjelasan',
        '{tanggal}' => 'Tanggal penandatanganan',
        '{klinik}' => 'Nama klinik',
        '{cabang}' => 'Nama cabang',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tindakans(): HasMany
    {
        return $this->hasMany(Tindakan::class);
    }

    /** @param  array<string, string|null>  $nilai  placeholder => nilai */
    public function render(array $nilai): string
    {
        return strtr($this->isi, array_map(fn ($v) => (string) ($v ?? '-'), $nilai));
    }

    public function auditLabel(): ?string
    {
        return $this->nama;
    }
}
