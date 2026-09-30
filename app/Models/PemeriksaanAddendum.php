<?php

namespace App\Models;

use App\Enums\BagianAddendum;
use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Koreksi rekam medis setelah ditandatangani (PRD RM-07, Permenkes 24/2022: isi RME tidak dihapus, hanya dikoreksi
 * dengan jejak). Append-only: ubah & hapus lewat model ditolak.
 */
#[Table('pemeriksaan_addendums')]
#[Fillable(['pemeriksaan_id', 'user_id', 'bagian', 'isi', 'alasan', 'created_at'])]
class PemeriksaanAddendum extends Model
{
    use Auditable;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Addendum rekam medis tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Addendum rekam medis tidak boleh dihapus.'));
    }

    protected function casts(): array
    {
        return [
            'bagian' => BagianAddendum::class,
            'created_at' => 'datetime',
        ];
    }

    public function pemeriksaan(): BelongsTo
    {
        return $this->belongsTo(Pemeriksaan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function auditPasienId(): ?int
    {
        return app(AuditService::class)->pasienDariKunjungan(
            Pemeriksaan::whereKey($this->pemeriksaan_id)->value('kunjungan_id'),
        );
    }
}
