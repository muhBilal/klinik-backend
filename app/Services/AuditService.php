<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Concerns\Auditable;
use App\Models\Kunjungan;
use App\Support\CabangAktif;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Penulis tunggal `audit_logs`. Dipakai trait Auditable (perubahan data) dan dipanggil langsung untuk
 * akses/aktivitas: lihat rekam medis, unduh berkas, login, logout, 2FA, ubah pengaturan.
 */
class AuditService
{
    /** Kolom yang tidak pernah dicatat nilainya (rahasia atau tidak bermakna). */
    private const ABAIKAN = [
        'created_at', 'updated_at', 'deleted_at', 'password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step',
    ];

    /** @var array<int, int|null> cache pasien_id per kunjungan dalam satu request */
    private array $pasienKunjungan = [];

    /**
     * @param  array{user_id?: int|null, cabang_id?: int|null, pasien_id?: int|null, label?: string|null, perubahan?: array|null}  $opsi
     */
    public function catat(string $aksi, ?string $tipe = null, ?int $subjekId = null, array $opsi = []): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => array_key_exists('user_id', $opsi) ? $opsi['user_id'] : auth()->id(),
            'cabang_id' => array_key_exists('cabang_id', $opsi) ? $opsi['cabang_id'] : app(CabangAktif::class)->id(),
            'aksi' => $aksi,
            'tipe' => $tipe,
            'subjek_id' => $subjekId,
            'pasien_id' => $opsi['pasien_id'] ?? null,
            'label' => isset($opsi['label']) ? Str::limit($opsi['label'], 250) : null,
            'perubahan' => $opsi['perubahan'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  Model&Auditable  $model
     */
    public function catatModel(string $aksi, Model $model): ?AuditLog
    {
        $abaikan = [...self::ABAIKAN, ...$model->auditAbaikan()];
        $softDelete = in_array(SoftDeletes::class, class_uses_recursive($model), true);

        $perubahan = match ($aksi) {
            'buat' => collect($model->getAttributes())->except($abaikan)->reject(fn ($v) => $v === null)
                ->map(fn ($v) => ['lama' => null, 'baru' => $v]),
            'ubah' => collect($model->getChanges())->except($abaikan)
                ->map(fn ($v, $k) => ['lama' => $model->getRawOriginal($k), 'baru' => $v]),
            // Soft delete: data tetap ada, cukup catat kejadiannya. Hapus permanen: simpan salinan terakhir.
            'hapus' => $softDelete && ! $model->isForceDeleting() ? collect()
                : collect($model->getAttributes())->except($abaikan)->reject(fn ($v) => $v === null)
                    ->map(fn ($v) => ['lama' => $v, 'baru' => null]),
            default => collect(),
        };

        if ($aksi === 'ubah' && $perubahan->isEmpty()) {
            return null;
        }

        return $this->catat($aksi, $model->auditTipe(), $model->getKey(), [
            'pasien_id' => $model->auditPasienId(),
            'label' => $model->auditLabel(),
            'perubahan' => $perubahan->isEmpty() ? null : $perubahan->all(),
            // Data milik cabang tertentu dicatat dengan cabang datanya, bukan cabang yang sedang dipilih.
            ...($model->getAttribute('cabang_id') ? ['cabang_id' => $model->getAttribute('cabang_id')] : []),
        ]);
    }

    /** pasien_id dari sebuah kunjungan (lintas cabang), di-cache per request. */
    public function pasienDariKunjungan(?int $kunjunganId): ?int
    {
        if (! $kunjunganId) {
            return null;
        }

        return $this->pasienKunjungan[$kunjunganId] ??= Kunjungan::withoutGlobalScope('cabang')
            ->whereKey($kunjunganId)->value('pasien_id');
    }
}
