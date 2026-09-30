<?php

namespace App\Models\Concerns;

use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Catat buat/ubah/hapus/pulihkan model ke `audit_logs` secara otomatis.
 *
 * Hanya event model yang tercatat: operasi massal (`Model::where(...)->update()`, `$relasi()->delete()`)
 * TIDAK memicu event — untuk data yang wajib diaudit, ubah/hapus per model (`->get()->each->delete()`).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => app(AuditService::class)->catatModel('buat', $model));
        static::updated(fn (Model $model) => app(AuditService::class)->catatModel('ubah', $model));
        static::deleted(fn (Model $model) => app(AuditService::class)->catatModel('hapus', $model));

        // Hanya ada bila model memakai SoftDeletes
        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => app(AuditService::class)->catatModel('pulihkan', $model));
        }
    }

    /** Jenis data di kolom `audit_logs.tipe`, mis. `pasien`, `kunjungan_tindakan`. */
    public function auditTipe(): string
    {
        return Str::snake(class_basename($this));
    }

    /** Teks ringkas yang mudah dikenali di daftar audit, mis. "000123 · Budi". */
    public function auditLabel(): ?string
    {
        return null;
    }

    /** Pasien pemilik data ini (untuk jejak akses per pasien). */
    public function auditPasienId(): ?int
    {
        return null;
    }

    /** Kolom tambahan yang tidak dicatat nilainya. @return list<string> */
    public function auditAbaikan(): array
    {
        return [];
    }
}
