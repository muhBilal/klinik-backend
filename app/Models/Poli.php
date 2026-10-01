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
#[Fillable(['kode', 'nama', 'spesialisasi', 'tarif_konsultasi', 'tindakan_konsultasi_id', 'is_active'])]
class Poli extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'tarif_konsultasi' => 'integer',
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

    /** Treatment jasa konsultasi yang ditautkan (opsional); bila diisi, tarif konsultasi ikut harga per cabang (AD-01). */
    public function tindakanKonsultasi(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class, 'tindakan_konsultasi_id');
    }

    /** Treatment konsultasi dengan `tarif_cabang` untuk satu cabang, atau null bila tidak ditautkan / treatment nonaktif/terhapus. */
    public function jasaKonsultasi(?int $cabangId): ?Tindakan
    {
        if (! $this->tindakan_konsultasi_id) {
            return null;
        }

        return Tindakan::query()->whereKey($this->tindakan_konsultasi_id)->denganHargaCabang($cabangId)->first();
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
