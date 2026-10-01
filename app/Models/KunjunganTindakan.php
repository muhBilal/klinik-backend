<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Table('kunjungan_tindakans')]
#[Fillable(['kunjungan_id', 'tindakan_id', 'jumlah', 'tarif', 'petugas_id', 'icd9cm_id', 'gigi', 'permukaan', 'rencana_item_id', 'paket_pasien_item_id', 'keterangan'])]
class KunjunganTindakan extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'tarif' => 'integer',
            'gigi' => 'integer',
        ];
    }

    /** Item rencana perawatan gigi yang dikerjakan tindakan ini (DG-02). */
    public function rencanaItem(): BelongsTo
    {
        return $this->belongsTo(RencanaPerawatanItem::class, 'rencana_item_id');
    }

    /** Sesi paket pasien yang dipakai tindakan ini (TR-02) → ditagih Rp 0. */
    public function paketItem(): BelongsTo
    {
        return $this->belongsTo(PaketPasienItem::class, 'paket_pasien_item_id');
    }

    /** Kondisi odontogram hasil otomatis tindakan per gigi (DG-01/07). */
    public function kondisiGigi(): HasMany
    {
        return $this->hasMany(OdontogramKondisi::class);
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    /** Petugas tambahan (asisten, terapis kedua) selain pelaksana utama — AN-03, dasar split komisi. */
    public function petugasTambahan(): HasMany
    {
        return $this->hasMany(KunjunganTindakanPetugas::class);
    }

    /** Petugas yang melakukan tindakan (dokter/terapis/perawat, AN-03); dasar komisi. */
    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'petugas_id')->withTrashed();
    }

    public function icd9cm(): BelongsTo
    {
        return $this->belongsTo(Icd9cm::class);
    }

    /** Catatan pelaksanaan: area, parameter alat, face chart (RM-05). */
    public function catatan(): HasOne
    {
        return $this->hasOne(CatatanTindakan::class);
    }

    public function informedConsents(): HasMany
    {
        return $this->hasMany(InformedConsent::class);
    }

    /** Pemakaian BHP nyata tindakan ini (IN-02). */
    public function bhps(): HasMany
    {
        return $this->hasMany(KunjunganTindakanBhp::class);
    }

    public function auditPasienId(): ?int
    {
        return app(AuditService::class)->pasienDariKunjungan($this->kunjungan_id);
    }
}
