<?php

namespace App\Models\Concerns;

use App\Models\Cabang;
use App\Support\CabangAktif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data transaksi milik satu cabang (kunjungan, resep, tagihan, berkas).
 * - Global scope `cabang`: query otomatis dibatasi ke cabang aktif (bila ada).
 *   Lintas cabang yang disengaja (riwayat pasien) -> `withoutGlobalScope('cabang')`.
 * - `cabang_id` diisi dari cabang aktif saat create bila belum diisi.
 */
trait DalamCabang
{
    public static function bootDalamCabang(): void
    {
        static::addGlobalScope('cabang', function (Builder $query) {
            if ($id = app(CabangAktif::class)->id()) {
                $query->where($query->getModel()->qualifyColumn('cabang_id'), $id);
            }
        });

        static::creating(function (Model $model) {
            $model->cabang_id ??= app(CabangAktif::class)->untukDataBaru();
        });
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class)->withTrashed();
    }
}
