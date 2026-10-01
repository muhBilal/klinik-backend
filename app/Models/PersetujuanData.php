<?php

namespace App\Models;

use App\Enums\HubunganPenandatangan;
use App\Enums\JenisPersetujuanData;
use App\Enums\StatusPersetujuanData;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persetujuan data pribadi pasien (PRD PS-04, UU No. 27/2022 PDP). Satu baris = satu jenis persetujuan (pemrosesan / marketing)
 * yang ditandatangani; paling banyak satu baris `berlaku` per pasien per jenis. Persetujuan baru membuat yang lama `diganti`,
 * pencabutan membuatnya `dicabut`. Tidak pernah dihapus (bukti persetujuan).
 */
#[Table('persetujuan_datas')]
#[Fillable([
    'uuid', 'pasien_id', 'cabang_id', 'jenis', 'kanal', 'isi', 'status', 'penandatangan_nama', 'hubungan', 'ttd',
    'dibuat_oleh', 'ditandatangani_at', 'berakhir_at', 'dicabut_oleh', 'alasan_cabut', 'checksum', 'ip_address',
])]
#[Hidden(['id', 'ttd', 'checksum', 'ip_address'])]
class PersetujuanData extends Model
{
    use Auditable;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'jenis' => JenisPersetujuanData::class,
            'status' => StatusPersetujuanData::class,
            'hubungan' => HubunganPenandatangan::class,
            'kanal' => 'array',
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
            $this->uuid, $this->pasien_id, $this->jenis?->value ?? $this->jenis, json_encode($this->kanal ?? []), $this->isi,
            $this->penandatangan_nama, $this->hubungan?->value ?? $this->hubungan, $this->ttd, $this->ditandatangani_at?->toIso8601String(),
        ]));
    }

    public function checksumValid(): bool
    {
        return hash_equals((string) $this->checksum, $this->hitungChecksum());
    }

    public function auditLabel(): ?string
    {
        return "Persetujuan {$this->jenis?->label()} · {$this->penandatangan_nama}";
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
