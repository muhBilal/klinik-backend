<?php

namespace App\Models;

use App\Enums\StatusResep;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('reseps')]
#[Fillable(['no_resep', 'kunjungan_id', 'dokter_id', 'status', 'catatan', 'apoteker_id', 'diserahkan_at'])]
class Resep extends Model
{
    protected function casts(): array
    {
        return [
            'status' => StatusResep::class,
            'diserahkan_at' => 'datetime',
        ];
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ResepItem::class);
    }

    public function dokter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dokter_id');
    }

    public function apoteker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'apoteker_id');
    }
}
