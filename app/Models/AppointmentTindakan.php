<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Treatment yang dibooking. Durasi & buffer di-snapshot (BK-02) agar perubahan katalog tidak
 * menggeser jadwal yang sudah dibuat.
 */
#[Table('appointment_tindakans')]
#[Fillable(['appointment_id', 'tindakan_id', 'durasi_menit', 'buffer_menit'])]
class AppointmentTindakan extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'durasi_menit' => 'integer',
            'buffer_menit' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }
}
