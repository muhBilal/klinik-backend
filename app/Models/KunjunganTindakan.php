<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('kunjungan_tindakans')]
#[Fillable(['kunjungan_id', 'tindakan_id', 'jumlah', 'tarif', 'keterangan'])]
class KunjunganTindakan extends Model
{
    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'tarif' => 'integer',
        ];
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class);
    }
}
