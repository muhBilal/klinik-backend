<?php

namespace App\Models;

use App\Enums\JenisCatatanTindakan;
use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catatan pelaksanaan satu tindakan kunjungan (PRD RM-05): area, catatan bebas, parameter alat (ES-02)
 * dan titik face chart injeksi (ES-01). Terkunci bersama rekam medis setelah pemeriksaan ditandatangani.
 */
#[Table('catatan_tindakans')]
#[Fillable(['kunjungan_tindakan_id', 'jenis', 'area', 'catatan', 'parameter', 'sumber_daya_id', 'dicatat_oleh'])]
class CatatanTindakan extends Model
{
    use Auditable;

    /** Parameter energy device yang dikenali: kunci => aturan validasi (ES-02). */
    public const PARAMETER = [
        'panjang_gelombang_nm' => ['nullable', 'numeric', 'between:100,20000'],
        'fluence_j_cm2' => ['nullable', 'numeric', 'between:0,1000'],
        'spot_size_mm' => ['nullable', 'numeric', 'between:0,100'],
        'durasi_pulsa_ms' => ['nullable', 'numeric', 'between:0,10000'],
        'frekuensi_hz' => ['nullable', 'numeric', 'between:0,1000'],
        'energi_total_j' => ['nullable', 'numeric', 'between:0,1000000'],
        'jumlah_shot' => ['nullable', 'integer', 'between:0,100000'],
        'jumlah_pass' => ['nullable', 'integer', 'between:0,100'],
        'pendingin' => ['nullable', 'string', 'max:100'],
        'reaksi_kulit' => ['nullable', 'string', 'in:tidak_ada,eritema_ringan,eritema_sedang,eritema_berat,edema,purpura,lepuh,hiperpigmentasi,lainnya'],
        'endpoint_klinis' => ['nullable', 'string', 'max:255'],
    ];

    protected function casts(): array
    {
        return [
            'jenis' => JenisCatatanTindakan::class,
            'parameter' => 'array',
        ];
    }

    public function kunjunganTindakan(): BelongsTo
    {
        return $this->belongsTo(KunjunganTindakan::class);
    }

    public function titiks(): HasMany
    {
        return $this->hasMany(CatatanTindakanTitik::class)->orderBy('id');
    }

    public function alat(): BelongsTo
    {
        return $this->belongsTo(SumberDaya::class, 'sumber_daya_id')->withoutGlobalScope('cabang')->withTrashed();
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh')->withTrashed();
    }

    public function auditPasienId(): ?int
    {
        return app(AuditService::class)->pasienDariKunjungan(
            KunjunganTindakan::whereKey($this->kunjungan_tindakan_id)->value('kunjungan_id'),
        );
    }
}
