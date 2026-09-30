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
#[Fillable(['kunjungan_id', 'tindakan_id', 'jumlah', 'tarif', 'petugas_id', 'icd9cm_id', 'keterangan'])]
class KunjunganTindakan extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'tarif' => 'integer',
        ];
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
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
