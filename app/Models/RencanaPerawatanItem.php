<?php

namespace App\Models;

use App\Enums\StatusItemRencana;
use App\Models\Concerns\Auditable;
use App\Support\Gigi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu langkah rencana perawatan gigi (PRD DG-02). Dikerjakan lewat tindakan kunjungan (`kunjungan_tindakans.rencana_item_id`);
 * menjadi `selesai` saat kunjungan itu ditutup & ditandatangani.
 */
#[Table('rencana_perawatan_items')]
#[Fillable(['rencana_perawatan_id', 'fase', 'urutan', 'gigi', 'permukaan', 'tindakan_id', 'jumlah', 'tarif', 'keterangan', 'status', 'selesai_at'])]
class RencanaPerawatanItem extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'fase' => 'integer',
            'urutan' => 'integer',
            'gigi' => 'integer',
            'jumlah' => 'integer',
            'tarif' => 'integer',
            'status' => StatusItemRencana::class,
            'selesai_at' => 'datetime',
        ];
    }

    public function rencana(): BelongsTo
    {
        return $this->belongsTo(RencanaPerawatan::class, 'rencana_perawatan_id');
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    /** Tindakan kunjungan yang mengerjakan item ini (terbaru; diurutkan, bukan latestOfMany — lihat Kunjungan::resep). */
    public function pelaksanaan(): HasOne
    {
        return $this->hasOne(KunjunganTindakan::class, 'rencana_item_id')->orderByDesc('kunjungan_tindakans.id');
    }

    public function auditLabel(): ?string
    {
        return trim(($this->tindakan?->nama ?? 'Item rencana').' '.(Gigi::format($this->gigi, $this->permukaan) ?? ''));
    }

    public function auditPasienId(): ?int
    {
        return $this->rencana?->pasien_id;
    }
}
