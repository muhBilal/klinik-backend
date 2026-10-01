<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('paket_items')]
#[Fillable(['paket_id', 'tindakan_id', 'jumlah_sesi'])]
class PaketItem extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['jumlah_sesi' => 'integer'];
    }

    public function paket(): BelongsTo
    {
        return $this->belongsTo(Paket::class)->withTrashed();
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }
}
