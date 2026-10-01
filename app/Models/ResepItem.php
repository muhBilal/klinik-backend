<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('resep_items')]
#[Fillable(['resep_id', 'obat_id', 'racikan', 'nama_racikan', 'bentuk', 'jumlah_racikan', 'satuan_racikan', 'jumlah', 'aturan_pakai', 'harga', 'biaya_racik'])]
class ResepItem extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'harga' => 'integer',
            'racikan' => 'boolean',
            'jumlah_racikan' => 'float',
            'biaya_racik' => 'integer',
        ];
    }

    public const BENTUK = ['krim', 'salep', 'gel', 'losion', 'kapsul', 'puyer', 'sirup', 'lainnya'];

    /** Komponen racikan (FR-01); kosong untuk obat jadi. */
    public function komponens(): HasMany
    {
        return $this->hasMany(ResepItemKomponen::class)->orderBy('id');
    }

    /** Label untuk tagihan & etiket: nama obat, atau "Racikan X (krim 30 g)". */
    public function label(): string
    {
        if (! $this->racikan) {
            return "{$this->obat?->nama} ({$this->obat?->satuan})";
        }

        $isi = $this->jumlah_racikan ? ' '.rtrim(rtrim(number_format($this->jumlah_racikan, 2, ',', ''), '0'), ',').' '.$this->satuan_racikan : '';

        return "Racikan {$this->nama_racikan} ({$this->bentuk}{$isi})";
    }

    public function obat(): BelongsTo
    {
        return $this->belongsTo(Obat::class)->withTrashed();
    }

    public function auditPasienId(): ?int
    {
        return app(AuditService::class)->pasienDariKunjungan(
            Resep::withoutGlobalScope('cabang')->whereKey($this->resep_id)->value('kunjungan_id'),
        );
    }
}
