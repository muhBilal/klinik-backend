<?php

namespace App\Models;

use App\Enums\Penjamin;
use App\Enums\StatusKunjungan;
use App\Enums\StatusResep;
use App\Enums\StatusTagihan;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Kunjungan milik satu cabang (global scope `cabang`, lihat DalamCabang).
 */
#[Table('kunjungans')]
#[Fillable([
    'cabang_id', 'no_registrasi', 'pasien_id', 'poli_id', 'dokter_id', 'tanggal', 'no_antrian',
    'penjamin', 'no_penjamin', 'keluhan', 'akses_terbatas', 'status', 'dipanggil_at', 'selesai_at', 'created_by',
])]
class Kunjungan extends Model
{
    use Auditable, DalamCabang;

    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'penjamin' => Penjamin::class,
            'status' => StatusKunjungan::class,
            'dipanggil_at' => 'datetime',
            'selesai_at' => 'datetime',
            'akses_terbatas' => 'boolean',
        ];
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function poli(): BelongsTo
    {
        return $this->belongsTo(Poli::class)->withTrashed();
    }

    public function dokter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dokter_id')->withTrashed();
    }

    public function pemeriksaan(): HasOne
    {
        return $this->hasOne(Pemeriksaan::class);
    }

    public function tindakans(): HasMany
    {
        return $this->hasMany(KunjunganTindakan::class);
    }

    /** Semua resep kunjungan; satu kunjungan boleh punya resep tambahan (8.3 #3). */
    public function reseps(): HasMany
    {
        return $this->hasMany(Resep::class)->withoutGlobalScope('cabang');
    }

    /**
     * Resep terbaru yang masih berlaku (bukan batal) — dipakai alur pemeriksaan & farmasi.
     * Diurutkan, bukan `latestOfMany()`: join subquery-nya membuat kolom di eager-load select ambigu.
     */
    public function resep(): HasOne
    {
        return $this->hasOne(Resep::class)->withoutGlobalScope('cabang')
            ->where('reseps.status', '!=', StatusResep::Batal->value)
            ->orderByDesc('reseps.id');
    }

    public function tagihans(): HasMany
    {
        return $this->hasMany(Tagihan::class)->withoutGlobalScope('cabang');
    }

    /** Tagihan terbaru yang masih berlaku (bukan batal). */
    public function tagihan(): HasOne
    {
        return $this->hasOne(Tagihan::class)->withoutGlobalScope('cabang')
            ->where('tagihans.status', '!=', StatusTagihan::Batal->value)
            ->orderByDesc('tagihans.id');
    }

    /** Informed consent kunjungan ini, termasuk yang ditolak/dicabut (RM-03). */
    public function informedConsents(): HasMany
    {
        return $this->hasMany(InformedConsent::class)->orderBy('id');
    }

    /** Kondisi odontogram yang dicatat pada kunjungan ini (DG-01). */
    public function odontogramDicatat(): HasMany
    {
        return $this->hasMany(OdontogramKondisi::class)->orderBy('id');
    }

    /** Kondisi odontogram kunjungan sebelumnya yang diakhiri pada kunjungan ini. */
    public function odontogramDiakhiri(): HasMany
    {
        return $this->hasMany(OdontogramKondisi::class, 'berakhir_kunjungan_id')->orderBy('id');
    }

    /** Pemeriksaan masih terbuka (belum ditutup & ditandatangani). */
    public function terbuka(): bool
    {
        return in_array($this->status, [StatusKunjungan::Menunggu, StatusKunjungan::Diperiksa], true);
    }

    public function berkas(): HasMany
    {
        return $this->hasMany(Berkas::class);
    }

    /**
     * Relasi rekam medis (SOAP, diagnosa, tindakan + catatan tindakan, consent, tanda tangan, addendum, resep)
     * dengan kolom seperlunya. Dipakai detail kunjungan dan riwayat pasien.
     */
    public static function relasiRekamMedis(): array
    {
        return [
            'pemeriksaan' => fn ($q) => $q->select(['id', 'kunjungan_id', ...Pemeriksaan::VITAL_FIELDS, ...Pemeriksaan::SOAP_FIELDS,
                'dokter_id', 'perawat_id', 'ditandatangani_at', 'ditandatangani_oleh']),
            'pemeriksaan.penandatangan:id,name,sip',
            'pemeriksaan.diagnosas:id,pemeriksaan_id,icd10_id,jenis',
            'pemeriksaan.diagnosas.icd10:id,kode,nama,sensitif',
            'pemeriksaan.addendums:id,pemeriksaan_id,user_id,bagian,isi,alasan,created_at',
            'pemeriksaan.addendums.user:id,name',
            'tindakans:id,kunjungan_id,tindakan_id,jumlah,tarif,petugas_id,icd9cm_id,gigi,permukaan,rencana_item_id,paket_pasien_item_id,keterangan',
            'tindakans.paketItem:id,paket_pasien_id,tindakan_id,jumlah_sesi',
            'tindakans.paketItem.paketPasien:id,no_paket,nama',
            'tindakans.tindakan:id,nama,jenis_catatan,template_consent_id,protokol_foto_id,per_gigi,kondisi_gigi_hasil',
            'tindakans.petugas:id,name',
            'tindakans.icd9cm:id,kode,nama',
            'tindakans.catatan:id,kunjungan_tindakan_id,jenis,area,catatan,parameter,sumber_daya_id',
            'tindakans.catatan.alat:id,kode,nama',
            'tindakans.catatan.titiks:id,catatan_tindakan_id,tampilan,x,y,area,obat_id,batch_id,jumlah,satuan,kedalaman,alat,catatan',
            'tindakans.catatan.titiks.obat:id,nama,satuan',
            'tindakans.catatan.titiks.batch:id,no_batch,kedaluwarsa',
            'informedConsents:id,uuid,kunjungan_id,kunjungan_tindakan_id,template_consent_id,judul,tindakan_nama,status,penandatangan_nama,hubungan,ditandatangani_at,dicabut_at,alasan_cabut',
            'resep:id,kunjungan_id,no_resep,status,catatan',
            'resep.items:id,resep_id,obat_id,jumlah,aturan_pakai,harga',
            'odontogramDicatat:id,kunjungan_id,kunjungan_tindakan_id,gigi,permukaan,kondisi,keterangan',
            'odontogramDiakhiri:id,kunjungan_id,berakhir_kunjungan_id,berakhir_karena_id,gigi,permukaan,kondisi',
        ];
    }

    /** Nama relasi rekam medis (tanpa sub-relasi), untuk dilepas dari kunjungan berakses terbatas. */
    public const RELASI_RME = ['pemeriksaan', 'tindakans', 'informedConsents', 'resep', 'odontogramDicatat', 'odontogramDiakhiri'];

    /**
     * Relasi untuk halaman detail kunjungan / pemeriksaan. `$rekamMedis = false` untuk pengguna tanpa izin
     * rme.lihat: hanya data administrasi (tanpa SOAP, diagnosa, tindakan, resep).
     */
    public function loadDetail(bool $rekamMedis = true): static
    {
        return $this->load([
            'pasien:id,no_rm,nama,jenis_kelamin,tanggal_lahir,golongan_darah,alergi',
            'poli:id,kode,nama,spesialisasi,tarif_konsultasi',
            'dokter:id,name,sip',
            'cabang:id,kode,nama',
            ...($rekamMedis ? [...self::relasiRekamMedis(), 'resep.items.obat:id,nama,satuan,stok'] : []),
            'tagihan:id,kunjungan_id,no_tagihan,total,grand_total,status',
        ]);
    }

    public function auditLabel(): ?string
    {
        return $this->no_registrasi;
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }
}
