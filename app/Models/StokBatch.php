<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok satu obat, satu cabang, satu batch (PRD IN-01). Pengeluaran memakai FEFO:
 * batch dengan kedaluwarsa terdekat dipakai lebih dulu.
 */
#[Table('stok_batches')]
#[Fillable(['obat_id', 'cabang_id', 'no_batch', 'kedaluwarsa', 'jumlah', 'jumlah_awal', 'dibuka_at', 'kedaluwarsa_dibuka_at'])]
class StokBatch extends Model
{
    use Auditable, DalamCabang;

    protected function casts(): array
    {
        return [
            'kedaluwarsa' => 'date:Y-m-d',
            'jumlah' => 'float',
            'jumlah_awal' => 'float',
            'dibuka_at' => 'datetime',
            'kedaluwarsa_dibuka_at' => 'datetime',
        ];
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class)->withTrashed();
    }

    /** Batch yang masih punya isi dan belum lewat masa pakai. */
    public function scopeTersedia(Builder $query): void
    {
        $query->where('jumlah', '>', 0)
            ->where(fn ($w) => $w->whereNull('kedaluwarsa')->orWhereDate('kedaluwarsa', '>=', today()))
            ->where(fn ($w) => $w->whereNull('kedaluwarsa_dibuka_at')->orWhere('kedaluwarsa_dibuka_at', '>=', now()));
    }

    /** Urutan FEFO: kedaluwarsa terdekat dulu; batch tanpa tanggal paling belakang. */
    public function scopeUrutFefo(Builder $query): void
    {
        $query->orderByRaw('CASE WHEN kedaluwarsa IS NULL THEN 1 ELSE 0 END')
            ->orderBy('kedaluwarsa')
            ->orderBy('id');
    }

    /** Sudah lewat kedaluwarsa batch atau masa pakai setelah dibuka. */
    public function kedaluwarsaSekarang(): bool
    {
        return ($this->kedaluwarsa && $this->kedaluwarsa->isPast())
            || ($this->kedaluwarsa_dibuka_at && $this->kedaluwarsa_dibuka_at->isPast());
    }

    public function auditLabel(): ?string
    {
        $obat = Obat::withTrashed()->whereKey($this->obat_id)->value('kode');

        return trim("{$obat} · ".($this->no_batch ?? 'tanpa batch'));
    }
}
