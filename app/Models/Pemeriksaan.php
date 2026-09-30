<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Table('pemeriksaans')]
#[Fillable([
    'kunjungan_id', 'tekanan_darah', 'nadi', 'suhu', 'respirasi', 'berat_badan', 'tinggi_badan',
    'subjektif', 'objektif', 'asesmen', 'plan', 'perawat_id', 'dokter_id', 'ditandatangani_at', 'ditandatangani_oleh', 'hash_ttd',
])]
class Pemeriksaan extends Model
{
    use Auditable;

    public const VITAL_FIELDS = ['tekanan_darah', 'nadi', 'suhu', 'respirasi', 'berat_badan', 'tinggi_badan'];

    public const SOAP_FIELDS = ['subjektif', 'objektif', 'asesmen', 'plan'];

    /** Rekam medis yang sudah ditandatangani dokter terkunci; koreksi lewat addendum (RM-07). */
    protected static function booted(): void
    {
        static::updating(function (Pemeriksaan $pemeriksaan) {
            if ($pemeriksaan->getOriginal('ditandatangani_at') !== null) {
                throw new LogicException('Rekam medis sudah ditandatangani; koreksi hanya lewat addendum.');
            }
        });
        static::deleting(fn () => throw new LogicException('Rekam medis tidak boleh dihapus.'));
    }

    public function ditandatangani(): bool
    {
        return $this->ditandatangani_at !== null;
    }

    protected function casts(): array
    {
        return [
            'ditandatangani_at' => 'datetime',
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

    public function penandatangan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditandatangani_oleh')->withTrashed();
    }

    public function addendums(): HasMany
    {
        return $this->hasMany(PemeriksaanAddendum::class)->orderBy('id');
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
