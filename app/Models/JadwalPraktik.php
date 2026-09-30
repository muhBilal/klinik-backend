<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pola jadwal praktik mingguan petugas (PRD BK-03). `hari` mengikuti Carbon::dayOfWeek (0 = Minggu).
 */
#[Table('jadwal_praktiks')]
#[Fillable(['cabang_id', 'user_id', 'hari', 'jam_mulai', 'jam_selesai', 'is_active'])]
class JadwalPraktik extends Model
{
    use Auditable, DalamCabang;

    protected function casts(): array
    {
        return [
            'hari' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
