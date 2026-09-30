<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harga treatment khusus satu cabang (menimpa `tindakans.tarif`), atau penanda treatment tidak dilayani di cabang itu.
 */
#[Table('tindakan_hargas')]
#[Fillable(['tindakan_id', 'cabang_id', 'tarif', 'tersedia'])]
class TindakanHarga extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'tarif' => 'integer',
            'tersedia' => 'boolean',
        ];
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function auditLabel(): ?string
    {
        $tindakan = $this->tindakan()->value('kode');
        $cabang = Cabang::withTrashed()->whereKey($this->cabang_id)->value('kode');

        return "{$tindakan} @ {$cabang}";
    }
}
