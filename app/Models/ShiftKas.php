<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift kas kasir (PRD BL-05). Ditutup dengan rekap per metode bayar & selisih kas fisik.
 */
#[Table('shift_kas')]
#[Fillable(['cabang_id', 'kasir_id', 'dibuka_at', 'ditutup_at', 'modal_awal', 'kas_fisik', 'selisih', 'catatan'])]
class ShiftKas extends Model
{
    use Auditable, DalamCabang;

    protected function casts(): array
    {
        return [
            'dibuka_at' => 'datetime',
            'ditutup_at' => 'datetime',
            'modal_awal' => 'integer',
            'kas_fisik' => 'integer',
            'selisih' => 'integer',
        ];
    }

    public function kasir(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kasir_id')->withTrashed();
    }

    public function pembayarans(): HasMany
    {
        return $this->hasMany(Pembayaran::class, 'shift_id');
    }

    public function terbuka(): bool
    {
        return $this->ditutup_at === null;
    }

    public function auditLabel(): ?string
    {
        return 'Shift '.$this->dibuka_at?->format('d/m/Y H:i');
    }
}
