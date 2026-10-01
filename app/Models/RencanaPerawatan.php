<?php

namespace App\Models;

use App\Enums\StatusRencanaPerawatan;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rencana perawatan gigi per pasien (PRD DG-02): item per gigi dikelompokkan per fase, dengan estimasi biaya dari harga
 * cabang penyusun. Milik pasien (lintas cabang untuk dibaca); diubah di cabang penyusunnya.
 */
#[Table('rencana_perawatans')]
#[Fillable([
    'pasien_id', 'cabang_id', 'kunjungan_id', 'dokter_id', 'judul', 'catatan', 'status', 'disetujui_at', 'disetujui_oleh',
    'penyetuju_nama', 'selesai_at', 'dibatalkan_at', 'dibatalkan_oleh', 'alasan_batal', 'created_by',
])]
class RencanaPerawatan extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'status' => StatusRencanaPerawatan::class,
            'disetujui_at' => 'datetime',
            'selesai_at' => 'datetime',
            'dibatalkan_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RencanaPerawatanItem::class)->orderBy('fase')->orderBy('urutan')->orderBy('id');
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class)->withTrashed();
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    public function dokter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dokter_id')->withTrashed();
    }

    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh')->withTrashed();
    }

    public function pembatal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibatalkan_oleh')->withTrashed();
    }

    public function auditLabel(): ?string
    {
        return $this->judul;
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }
}
