<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bahan habis pakai (BHP) standar satu treatment. `jumlah` dalam satuan obat dan boleh desimal (pemakaian fraksional).
 * Pemotongan stok otomatis dari BHP standar dikerjakan di modul Inventori (IN-02).
 */
#[Table('tindakan_bhps')]
#[Fillable(['tindakan_id', 'obat_id', 'jumlah'])]
class TindakanBhp extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'jumlah' => 'float',
        ];
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class)->withTrashed();
    }

    public function auditLabel(): ?string
    {
        $tindakan = $this->tindakan()->value('kode');
        $obat = Obat::withTrashed()->whereKey($this->obat_id)->value('kode');

        return "{$tindakan} · {$obat}";
    }
}
