<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu treatment dalam paket milik pasien: jumlah sesi & alokasi nilai per sesi (dasar pendapatan diterima di muka). */
#[Table('paket_pasien_items')]
#[Fillable(['paket_pasien_id', 'tindakan_id', 'jumlah_sesi', 'nilai_per_sesi'])]
class PaketPasienItem extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'jumlah_sesi' => 'integer',
            'nilai_per_sesi' => 'integer',
            'tindakan_id' => 'integer',
        ];
    }

    public function paketPasien(): BelongsTo
    {
        return $this->belongsTo(PaketPasien::class);
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    /** Tindakan kunjungan yang memakai sesi ini (termasuk kunjungan batal — saring per status kunjungan). */
    public function pemakaians(): HasMany
    {
        return $this->hasMany(KunjunganTindakan::class, 'paket_pasien_item_id');
    }

    public function auditPasienId(): ?int
    {
        return $this->paketPasien?->pasien_id;
    }
}
