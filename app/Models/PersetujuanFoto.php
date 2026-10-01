<?php

namespace App\Models;

use App\Enums\HubunganPenandatangan;
use App\Enums\StatusPersetujuanFoto;
use App\Enums\TingkatPersetujuanFoto;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consent foto klinis bertingkat per pasien (PRD FT-04, UU PDP: foto wajah = data pribadi spesifik).
 * Satu baris = satu persetujuan yang ditandatangani. Paling banyak satu baris `berlaku` per pasien; persetujuan baru
 * membuat yang lama `diganti`, pencabutan membuatnya `dicabut`. Tidak pernah dihapus.
 */
#[Table('persetujuan_fotos')]
#[Fillable([
    'uuid', 'pasien_id', 'cabang_id', 'kunjungan_id', 'tingkat', 'isi', 'status', 'penandatangan_nama', 'hubungan', 'ttd',
    'dibuat_oleh', 'ditandatangani_at', 'berakhir_at', 'dicabut_oleh', 'alasan_cabut', 'checksum', 'ip_address',
])]
#[Hidden(['id', 'ttd', 'checksum', 'ip_address'])]
class PersetujuanFoto extends Model
{
    use Auditable;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'tingkat' => TingkatPersetujuanFoto::class,
            'status' => StatusPersetujuanFoto::class,
            'hubungan' => HubunganPenandatangan::class,
            'ttd' => 'encrypted',
            'ditandatangani_at' => 'datetime',
            'berakhir_at' => 'datetime',
        ];
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class)->withTrashed();
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh')->withTrashed();
    }

    public function pencabut(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicabut_oleh')->withTrashed();
    }

    /** Sidik apa yang ditandatangani; tidak berubah saat diganti/dicabut (dicatat di kolom lain). */
    public function hitungChecksum(): string
    {
        return hash('sha256', implode('|', [
            $this->uuid, $this->pasien_id, $this->tingkat?->value ?? $this->tingkat, $this->isi, $this->penandatangan_nama,
            $this->hubungan?->value ?? $this->hubungan, $this->ttd, $this->ditandatangani_at?->toIso8601String(),
        ]));
    }

    public function checksumValid(): bool
    {
        return hash_equals((string) $this->checksum, $this->hitungChecksum());
    }

    public function auditLabel(): ?string
    {
        return "Persetujuan foto {$this->tingkat?->label()} · {$this->penandatangan_nama}";
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }

    public function auditAbaikan(): array
    {
        return ['ttd', 'isi', 'checksum'];
    }
}
