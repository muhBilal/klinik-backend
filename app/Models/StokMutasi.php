<?php

namespace App\Models;

use App\Enums\JenisMutasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('stok_mutasis')]
#[Fillable(['obat_id', 'jenis', 'jumlah', 'stok_akhir', 'referensi', 'keterangan', 'user_id'])]
class StokMutasi extends Model
{
    protected function casts(): array
    {
        return [
            'jenis' => JenisMutasi::class,
        ];
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
