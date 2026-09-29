<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('pemeriksaans')]
#[Fillable([
    'kunjungan_id', 'tekanan_darah', 'nadi', 'suhu', 'respirasi', 'berat_badan', 'tinggi_badan',
    'subjektif', 'objektif', 'asesmen', 'plan', 'perawat_id', 'dokter_id',
])]
class Pemeriksaan extends Model
{
    public const VITAL_FIELDS = ['tekanan_darah', 'nadi', 'suhu', 'respirasi', 'berat_badan', 'tinggi_badan'];

    public const SOAP_FIELDS = ['subjektif', 'objektif', 'asesmen', 'plan'];

    protected function casts(): array
    {
        return [
            'nadi' => 'integer',
            'respirasi' => 'integer',
            'suhu' => 'float',
            'berat_badan' => 'float',
            'tinggi_badan' => 'float',
        ];
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class);
    }

    public function diagnosas(): HasMany
    {
        return $this->hasMany(PemeriksaanDiagnosa::class)->orderByRaw("case when jenis = 'primer' then 0 else 1 end");
    }

    public function perawat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'perawat_id');
    }

    public function dokter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dokter_id');
    }
}
