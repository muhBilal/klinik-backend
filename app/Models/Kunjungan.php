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
     * Relasi rekam medis (SOAP, diagnosa, tindakan, resep) dengan kolom seperlunya.
     * Dipakai detail kunjungan dan riwayat pasien.
     */
    public static function relasiRekamMedis(): array
    {
        return [
            'pemeriksaan' => fn ($q) => $q->select(['id', 'kunjungan_id', ...Pemeriksaan::VITAL_FIELDS, ...Pemeriksaan::SOAP_FIELDS]),
            'pemeriksaan.diagnosas:id,pemeriksaan_id,icd10_id,jenis',
            'pemeriksaan.diagnosas.icd10:id,kode,nama',
            'tindakans:id,kunjungan_id,tindakan_id,jumlah,tarif',
            'tindakans.tindakan:id,nama',
            'resep:id,kunjungan_id,no_resep,status,catatan',
            'resep.items:id,resep_id,obat_id,jumlah,aturan_pakai,harga',
        ];
    }

    /**
     * Relasi untuk halaman detail kunjungan / pemeriksaan.
     */
    public function loadDetail(): static
    {
        return $this->load([
            'pasien:id,no_rm,nama,jenis_kelamin,tanggal_lahir,golongan_darah,alergi',
            'poli:id,kode,nama,tarif_konsultasi',
            'dokter:id,name,sip',
            ...self::relasiRekamMedis(),
            'resep.items.obat:id,nama,satuan,stok',
            'tagihan:id,kunjungan_id,no_tagihan,total,grand_total,status',
        ]);
    }
}
