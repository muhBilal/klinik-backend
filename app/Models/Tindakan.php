<?php

namespace App\Models;

use App\Enums\JenisCatatanTindakan;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Treatment / tindakan (PRD TR-01). Master pusat: `tarif` = harga dasar, ditimpa per cabang lewat `hargas`.
 */
#[Table('tindakans')]
#[Fillable(['kode', 'nama', 'kategori_id', 'icd9cm_id', 'template_consent_id', 'jenis_catatan', 'durasi_menit', 'buffer_menit', 'tarif', 'is_active'])]
class Tindakan extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'tarif' => 'integer',
            'durasi_menit' => 'integer',
            'buffer_menit' => 'integer',
            'is_active' => 'boolean',
            'jenis_catatan' => JenisCatatanTindakan::class,
            // Kolom hasil scope denganHargaCabang()
            'tarif_cabang' => 'integer',
            'tersedia' => 'boolean',
        ];
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriTindakan::class, 'kategori_id')->withTrashed();
    }

    /** Kode ICD-9-CM default, disalin ke tindakan kunjungan (RM-02). */
    public function icd9cm(): BelongsTo
    {
        return $this->belongsTo(Icd9cm::class);
    }

    /** Diisi = treatment wajib informed consent sebelum pemeriksaan ditutup (RM-03). */
    public function templateConsent(): BelongsTo
    {
        return $this->belongsTo(TemplateConsent::class)->withTrashed();
    }

    public function hargas(): HasMany
    {
        return $this->hasMany(TindakanHarga::class);
    }

    public function bhps(): HasMany
    {
        return $this->hasMany(TindakanBhp::class);
    }

    /** Ruang/alat yang boleh dipakai treatment ini (BK-01). Kosong = tidak butuh sumber daya khusus. */
    public function sumberDayas(): BelongsToMany
    {
        return $this->belongsToMany(SumberDaya::class, 'tindakan_sumber_dayas')->withoutGlobalScope('cabang');
    }

    /**
     * Tambah kolom `tarif_cabang` (harga cabang, atau harga dasar bila cabang tidak punya harga khusus) dan
     * `tersedia` (false bila treatment tidak dilayani di cabang itu). Tanpa cabang: harga dasar & tersedia.
     */
    public function scopeDenganHargaCabang(Builder $query, ?int $cabangId): void
    {
        $sub = '(SELECT h.%s FROM tindakan_hargas h WHERE h.tindakan_id = tindakans.id AND h.cabang_id = ?)';

        $query->selectRaw('COALESCE('.sprintf($sub, 'tarif').', tindakans.tarif) AS tarif_cabang', [$cabangId])
            ->selectRaw('COALESCE('.sprintf($sub, 'tersedia').', TRUE) AS tersedia', [$cabangId]);
    }

    /** Treatment yang tidak ditandai "tidak tersedia" di cabang ini. */
    public function scopeTersediaDi(Builder $query, ?int $cabangId): void
    {
        $query->when($cabangId, fn ($q) => $q->whereDoesntHave(
            'hargas', fn ($h) => $h->where('cabang_id', $cabangId)->where('tersedia', false),
        ));
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
