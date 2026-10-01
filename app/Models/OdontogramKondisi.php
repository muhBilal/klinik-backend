<?php

namespace App\Models;

use App\Enums\KondisiGigi;
use App\Enums\StatusKunjungan;
use App\Models\Concerns\Auditable;
use App\Support\Gigi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Satu kondisi di odontogram pasien (PRD DG-01): seluruh gigi (`permukaan` null) atau satu permukaan, dicatat pada satu
 * kunjungan. Kondisi yang tidak berlaku lagi **diakhiri** di kunjungan berikutnya (`berakhir_kunjungan_id`), bukan dihapus,
 * sehingga status odontogram pada kunjungan mana pun bisa disusun ulang. Aturan penggantian: `OdontogramService`.
 *
 * Bagian dari rekam medis kunjungan: setelah kunjungan ditutup & ditandatangani, isi kondisi terkunci — hanya boleh diakhiri
 * oleh kunjungan lain yang masih terbuka.
 */
#[Table('odontogram_kondisis')]
#[Fillable([
    'pasien_id', 'cabang_id', 'kunjungan_id', 'kunjungan_tindakan_id', 'gigi', 'permukaan', 'kondisi', 'keterangan', 'dicatat_oleh',
    'berakhir_kunjungan_id', 'berakhir_at', 'berakhir_oleh', 'berakhir_karena_id',
])]
class OdontogramKondisi extends Model
{
    use Auditable;

    public const KOLOM_BERAKHIR = ['berakhir_kunjungan_id', 'berakhir_at', 'berakhir_oleh', 'berakhir_karena_id', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (OdontogramKondisi $kondisi) {
            $isiBerubah = array_diff(array_keys($kondisi->getDirty()), self::KOLOM_BERAKHIR) !== [];
            if ($isiBerubah && ! self::kunjunganTerbuka($kondisi->getOriginal('kunjungan_id'))) {
                throw new LogicException('Kondisi odontogram kunjungan yang sudah ditutup tidak boleh diubah.');
            }

            $berakhirLama = $kondisi->getOriginal('berakhir_kunjungan_id');
            if ($berakhirLama && ! self::kunjunganTerbuka($berakhirLama)) {
                throw new LogicException('Pengakhiran kondisi odontogram pada kunjungan yang sudah ditutup tidak boleh diubah.');
            }
        });

        static::deleting(function (OdontogramKondisi $kondisi) {
            if (! self::kunjunganTerbuka($kondisi->kunjungan_id)) {
                throw new LogicException('Kondisi odontogram kunjungan yang sudah ditutup tidak boleh dihapus.');
            }
        });
    }

    private static function kunjunganTerbuka(?int $kunjunganId): bool
    {
        // toBase(): nilai mentah (value() Eloquent menerapkan cast enum).
        $status = Kunjungan::withoutGlobalScope('cabang')->whereKey($kunjunganId)->toBase()->value('status');

        return in_array($status, [StatusKunjungan::Menunggu->value, StatusKunjungan::Diperiksa->value], true);
    }

    protected function casts(): array
    {
        return [
            'pasien_id' => 'integer',
            'kunjungan_id' => 'integer',
            'kunjungan_tindakan_id' => 'integer',
            'berakhir_kunjungan_id' => 'integer',
            'berakhir_karena_id' => 'integer',
            'gigi' => 'integer',
            'kondisi' => KondisiGigi::class,
            'berakhir_at' => 'datetime',
        ];
    }

    /** Masih berlaku (belum diakhiri). */
    public function scopeAktif(Builder $query): void
    {
        $query->whereNull('berakhir_kunjungan_id');
    }

    /**
     * Status odontogram pada kunjungan `$kunjunganId`: dicatat di kunjungan itu atau sebelumnya, dan belum diakhiri
     * sampai kunjungan itu. Urutan kunjungan = urutan id (kunjungan dibuat berurutan).
     */
    public function scopeBerlakuPada(Builder $query, int $kunjunganId): void
    {
        $query->where('kunjungan_id', '<=', $kunjunganId)
            ->where(fn ($q) => $q->whereNull('berakhir_kunjungan_id')->orWhere('berakhir_kunjungan_id', '>', $kunjunganId));
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    public function kunjunganTindakan(): BelongsTo
    {
        return $this->belongsTo(KunjunganTindakan::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh')->withTrashed();
    }

    public function pengakhir(): BelongsTo
    {
        return $this->belongsTo(User::class, 'berakhir_oleh')->withTrashed();
    }

    /** Hasil otomatis tindakan per gigi (diubah lewat tindakannya, bukan manual). */
    public function turunanTindakan(): bool
    {
        return $this->kunjungan_tindakan_id !== null;
    }

    public function deskripsi(): string
    {
        $tempat = $this->permukaan
            ? "gigi {$this->gigi} ".Gigi::labelPermukaan($this->gigi, $this->permukaan)
            : "gigi {$this->gigi}";

        return "{$this->kondisi->label()} · {$tempat}";
    }

    public function auditLabel(): ?string
    {
        return $this->deskripsi();
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }
}
