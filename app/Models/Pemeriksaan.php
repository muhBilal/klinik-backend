<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('pemeriksaans')]
#[Fillable([
    'kunjungan_id', 'tekanan_darah', 'nadi', 'suhu', 'respirasi', 'berat_badan', 'tinggi_badan',
    'subjektif', 'objektif', 'asesmen', 'plan', 'perawat_id', 'dokter_id',
])]
class Pemeriksaan extends Model
{
    use Auditable;

    public const VITAL_FIELDS = ['tekanan_darah', 'nadi', 'suhu', 'respirasi', 'berat_badan', 'tinggi_badan'];

    public const SOAP_FIELDS = ['subjektif', 'objektif', 'asesmen', 'plan'];

    protected function casts(): array
    {
        return [
            'nadi' => 'integer',
            'respirasi' => 'integer',
            'suhu' => 'float',
            'berat_badan' => 'float',
            'tinggi_badan' => 'float',
        ];
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    public function diagnosas(): HasMany
    {
        return $this->hasMany(PemeriksaanDiagnosa::class)->orderByRaw("case when jenis = 'primer' then 0 else 1 end");
    }

    public function perawat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'perawat_id')->withTrashed();
    }

    public function dokter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dokter_id')->withTrashed();
    }

    public function auditLabel(): ?string
    {
        return "Pemeriksaan kunjungan #{$this->kunjungan_id}";
    }

    public function auditPasienId(): ?int
    {
        return app(AuditService::class)->pasienDariKunjungan($this->kunjungan_id);
    }
}
