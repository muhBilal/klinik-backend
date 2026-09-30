<?php

namespace App\Models;

use App\Enums\TipePengecualian;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuti / libur dan jadwal tambahan di luar pola mingguan (PRD BK-03).
 * `tipe = cuti` dengan `jam_mulai` null berarti libur sehari penuh.
 */
#[Table('jadwal_pengecualians')]
#[Fillable(['cabang_id', 'user_id', 'tanggal', 'tipe', 'jam_mulai', 'jam_selesai', 'keterangan'])]
class JadwalPengecualian extends Model
{
    use Auditable, DalamCabang;

    protected function casts(): array
    {
        return [
            'tanggal' => 'date:Y-m-d',
            'tipe' => TipePengecualian::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function sehariPenuh(): bool
    {
        return $this->jam_mulai === null;
    }
}
