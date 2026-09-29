<?php

namespace App\Models;

use App\Enums\MetodeBayar;
use App\Enums\StatusTagihan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('tagihans')]
#[Fillable([
    'no_tagihan', 'kunjungan_id', 'total', 'diskon', 'grand_total', 'status',
    'metode_bayar', 'dibayar', 'kembalian', 'kasir_id', 'dibayar_at',
])]
class Tagihan extends Model
{
    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'diskon' => 'integer',
            'grand_total' => 'integer',
            'dibayar' => 'integer',
            'kembalian' => 'integer',
            'status' => StatusTagihan::class,
            'metode_bayar' => MetodeBayar::class,
            'dibayar_at' => 'datetime',
        ];
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TagihanItem::class);
    }

    public function kasir(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kasir_id');
    }
}
