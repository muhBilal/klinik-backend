<?php

namespace App\Models;

use App\Enums\JenisMutasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('stok_mutasis')]
#[Fillable(['obat_id', 'cabang_id', 'batch_id', 'jenis', 'jumlah', 'stok_akhir', 'referensi', 'keterangan', 'user_id'])]
class StokMutasi extends Model
{
    protected function casts(): array
    {
        return [
            'jenis' => JenisMutasi::class,
            'jumlah' => 'float',
            'stok_akhir' => 'float',
        ];
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StokBatch::class, 'batch_id')->withoutGlobalScope('cabang');
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class)->withTrashed();
    }
}
