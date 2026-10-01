<?php

namespace App\Models;

use App\Enums\HubunganPenandatangan;
use App\Enums\JenisPersetujuanData;
use App\Enums\StatusPersetujuanFoto;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consent UU PDP per pasien (PRD PS-04): `pemrosesan` (wajib untuk pelayanan bila diatur) dan `marketing` (opt-in, boleh menolak).
 * Pola sama dengan consent foto: satu baris `berlaku` per jenis, yang baru menggantikan, pencabutan = `dicabut`, tidak pernah dihapus.
 */
#[Table('persetujuan_datas')]
#[Fillable([
    'uuid', 'pasien_id', 'cabang_id', 'jenis', 'setuju', 'isi', 'status', 'penandatangan_nama', 'hubungan', 'ttd', 'checksum',
    'dibuat_oleh', 'ditandatangani_at', 'berakhir_at', 'dicabut_oleh', 'alasan_cabut', 'ip_address',
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
            'setuju' => 'boolean',
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

    public function hitungChecksum(): string
    {
        return hash('sha256', implode('|', [
            $this->uuid, $this->pasien_id, $this->jenis?->value ?? $this->jenis, $this->setuju ? '1' : '0', $this->isi,
            $this->penandatangan_nama, $this->hubungan?->value ?? $this->hubungan, $this->ttd, $this->ditandatangani_at?->toIso8601String(),
        ]));
    }

    public function checksumValid(): bool
    {
        return hash_equals((string) $this->checksum, $this->hitungChecksum());
    }

    public function auditLabel(): ?string
    {
        return ($this->setuju ? 'Setuju' : 'Menolak').' '.$this->jenis?->label()." · {$this->penandatangan_nama}";
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
