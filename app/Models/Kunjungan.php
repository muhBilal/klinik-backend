<?php

namespace App\Models;

use App\Enums\Penjamin;
use App\Enums\StatusKunjungan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Table('kunjungans')]
#[Fillable([
    'no_registrasi', 'pasien_id', 'poli_id', 'dokter_id', 'tanggal', 'no_antrian',
    'penjamin', 'no_penjamin', 'keluhan', 'status', 'dipanggil_at', 'selesai_at', 'created_by',
])]
class Kunjungan extends Model
{
    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'penjamin' => Penjamin::class,
            'status' => StatusKunjungan::class,
            'dipanggil_at' => 'datetime',
            'selesai_at' => 'datetime',
        ];
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class);
    }

    public function poli(): BelongsTo
    {
        return $this->belongsTo(Poli::class);
    }

    public function dokter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dokter_id');
    }

    public function pemeriksaan(): HasOne
    {
        return $this->hasOne(Pemeriksaan::class);
    }

    public function tindakans(): HasMany
    {
        return $this->hasMany(KunjunganTindakan::class);
    }

    public function resep(): HasOne
    {
        return $this->hasOne(Resep::class);
    }

    public function tagihan(): HasOne
    {
        return $this->hasOne(Tagihan::class);
    }

    /**
     * Relasi lengkap untuk halaman detail / rekam medis.
     */
    public function loadDetail(): static
    {
        return $this->load([
            'pasien', 'poli', 'dokter:id,name,sip',
            'pemeriksaan.diagnosas.icd10', 'pemeriksaan.perawat:id,name', 'pemeriksaan.dokter:id,name',
            'tindakans.tindakan',
            'resep.items.obat', 'resep.apoteker:id,name',
            'tagihan.items', 'tagihan.kasir:id,name',
        ]);
    }
}
