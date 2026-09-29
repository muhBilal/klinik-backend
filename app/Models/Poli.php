<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('polis')]
#[Fillable(['kode', 'nama', 'tarif_konsultasi', 'is_active'])]
class Poli extends Model
{
    protected function casts(): array
    {
        return [
            'tarif_konsultasi' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function dokters(): HasMany
    {
        return $this->hasMany(User::class)->dokter();
    }

    public function kunjungans(): HasMany
    {
        return $this->hasMany(Kunjungan::class);
    }
}
