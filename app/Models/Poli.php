<?php

namespace App\Models;

use App\Enums\Spesialisasi;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('polis')]
#[Fillable(['kode', 'nama', 'spesialisasi', 'tindakan_konsultasi_id', 'is_active'])]
class Poli extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'tindakan_konsultasi_id' => 'integer',
            'is_active' => 'boolean',
            // Modul spesialisasi di pemeriksaan (PRD bagian 6), mis. odontogram untuk poli gigi
            'spesialisasi' => Spesialisasi::class,
        ];
    }

    public function dokters(): HasMany
    {
        return $this->hasMany(User::class)->dokter();
    }

    /** Treatment jasa konsultasi dokter yang ditagihkan otomatis tiap kunjungan poli ini (harga & komisi ikut katalog treatment). */
    public function tindakanKonsultasi(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class, 'tindakan_konsultasi_id')->withTrashed();
    }

    /**
     * Jasa konsultasi untuk kunjungan poli ini di satu cabang: treatment konsultasi + `tarif_cabang`. Null bila poli tanpa jasa
     * konsultasi, atau treatment-nya nonaktif / terhapus / ditandai tidak dilayani di cabang itu.
     */
    public function jasaKonsultasi(?int $cabangId): ?Tindakan
    {
        if (! $this->tindakan_konsultasi_id) {
            return null;
        }

        return Tindakan::query()->select(['id', 'kode', 'nama', 'tarif'])->denganHargaCabang($cabangId)
            ->where('is_active', true)->tersediaDi($cabangId)->find($this->tindakan_konsultasi_id);
    }

    /** Kunjungan di cabang aktif (global scope cabang). */
    public function kunjungans(): HasMany
    {
        return $this->hasMany(Kunjungan::class);
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
