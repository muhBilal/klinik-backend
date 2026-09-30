<?php

namespace App\Models;

use App\Enums\HubunganPenandatangan;
use App\Enums\StatusConsent;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Informed consent digital per tindakan (PRD RM-03). Naskah hasil render template dan tanda tangan di-snapshot saat
 * ditandatangani; tanda tangan (PNG data URL) terenkripsi dan hanya dikirim lewat endpoint detail yang tercatat audit.
 * Tidak pernah dihapus — penarikan persetujuan dicatat sebagai status `dicabut`.
 */
#[Table('informed_consents')]
#[Fillable([
    'uuid', 'cabang_id', 'kunjungan_id', 'pasien_id', 'kunjungan_tindakan_id', 'template_consent_id', 'judul', 'tindakan_nama',
    'isi', 'status', 'penandatangan_nama', 'hubungan', 'ttd_penandatangan', 'saksi_nama', 'ttd_saksi', 'dokter_id', 'dibuat_oleh',
    'ditandatangani_at', 'dicabut_at', 'dicabut_oleh', 'alasan_cabut', 'checksum', 'ip_address',
])]
#[Hidden(['id', 'ttd_penandatangan', 'ttd_saksi', 'checksum', 'ip_address'])]
class InformedConsent extends Model
{
    use Auditable;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'status' => StatusConsent::class,
            'hubungan' => HubunganPenandatangan::class,
            'ttd_penandatangan' => 'encrypted',
            'ttd_saksi' => 'encrypted',
            'ditandatangani_at' => 'datetime',
            'dicabut_at' => 'datetime',
        ];
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function kunjunganTindakan(): BelongsTo
    {
        return $this->belongsTo(KunjunganTindakan::class);
    }

    public function dokter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dokter_id')->withTrashed();
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh')->withTrashed();
    }

    public function pencabut(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicabut_oleh')->withTrashed();
    }

    /**
     * Sidik apa yang ditandatangani (naskah, keputusan awal, penanda tangan, tanda tangan, waktu); berubah bila baris
     * diubah di luar aplikasi. Pencabutan tidak mengubahnya karena dicatat di kolom terpisah.
     */
    public function hitungChecksum(): string
    {
        $keputusan = $this->status === StatusConsent::Ditolak ? 'tolak' : 'setuju';

        return hash('sha256', implode('|', [
            $this->uuid, $this->kunjungan_id, $this->judul, $this->isi, $keputusan,
            $this->penandatangan_nama, $this->hubungan?->value ?? $this->hubungan, $this->ttd_penandatangan,
            $this->saksi_nama, $this->ttd_saksi, $this->ditandatangani_at?->toIso8601String(),
        ]));
    }

    public function checksumValid(): bool
    {
        return hash_equals((string) $this->checksum, $this->hitungChecksum());
    }

    public function auditLabel(): ?string
    {
        return "{$this->judul} · {$this->penandatangan_nama}";
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }

    /** Tanda tangan & naskah panjang tidak disalin ke audit log (sudah tersimpan utuh di baris ini). */
    public function auditAbaikan(): array
    {
        return ['ttd_penandatangan', 'ttd_saksi', 'isi', 'checksum'];
    }
}
