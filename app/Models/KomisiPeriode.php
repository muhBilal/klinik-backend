<?php

namespace App\Models;

use App\Enums\StatusKomisiPeriode;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Rekap komisi satu cabang untuk rentang tanggal pembayaran (PRD KM-03). `DalamCabang`: dibatasi cabang aktif. Periode satu cabang tidak boleh tumpang tindih sehingga
 * satu tagihan hanya masuk satu rekap. Setelah `disetujui`, baris & periodenya terkunci (model menolak ubah/hapus).
 */
#[Table('komisi_periodes')]
#[Fillable([
    'cabang_id', 'nama', 'mulai', 'selesai', 'status', 'dasar', 'total', 'dihitung_at', 'dihitung_oleh', 'disetujui_at', 'disetujui_oleh',
    'catatan', 'created_by',
])]
class KomisiPeriode extends Model
{
    use Auditable, DalamCabang;

    protected static function booted(): void
    {
        static::updating(function (KomisiPeriode $periode) {
            // getRawOriginal: getOriginal() mengembalikan enum (ter-cast).
            if ($periode->getRawOriginal('status') === StatusKomisiPeriode::Disetujui->value) {
                throw new LogicException('Rekap komisi yang sudah disetujui terkunci.');
            }
        });
        static::deleting(function (KomisiPeriode $periode) {
            if ($periode->status === StatusKomisiPeriode::Disetujui) {
                throw new LogicException('Rekap komisi yang sudah disetujui tidak boleh dihapus.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => StatusKomisiPeriode::class,
            'mulai' => 'date:Y-m-d',
            'selesai' => 'date:Y-m-d',
            'total' => 'integer',
            'dihitung_at' => 'datetime',
            'disetujui_at' => 'datetime',
            'cabang_id' => 'integer',
            // Hasil withSum (komisi-saya): PostgreSQL mengembalikan SUM sebagai string.
            'total_saya' => 'integer',
        ];
    }

    public function terkunci(): bool
    {
        return $this->status === StatusKomisiPeriode::Disetujui;
    }

    public function barises(): HasMany
    {
        return $this->hasMany(KomisiBaris::class)->orderBy('tanggal')->orderBy('id');
    }

    public function penghitung(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dihitung_oleh')->withTrashed();
    }

    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh')->withTrashed();
    }

    public function auditLabel(): ?string
    {
        return $this->nama;
    }
}
