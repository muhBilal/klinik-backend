<?php

namespace App\Models;

use App\Enums\TipeSumberDaya;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ruang & alat yang bisa dibooking (PRD BK-01). Milik satu cabang.
 */
#[Table('sumber_dayas')]
#[Fillable(['cabang_id', 'kode', 'nama', 'tipe', 'is_active'])]
class SumberDaya extends Model
{
    use Auditable, DalamCabang, SoftDeletes;

    protected function casts(): array
    {
        return [
            'tipe' => TipeSumberDaya::class,
            'is_active' => 'boolean',
        ];
    }

    public function tindakans(): BelongsToMany
    {
        return $this->belongsToMany(Tindakan::class, 'tindakan_sumber_dayas');
    }

    /** Booking mendatang yang masih memakai ruang/alat ini (pencegah hapus). */
    public function appointmentsAktif(): BelongsToMany
    {
        return $this->belongsToMany(Appointment::class, 'appointment_sumber_dayas')
            ->withoutGlobalScope('cabang')
            ->memesanSlot()
            ->where('selesai_at', '>=', now());
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
