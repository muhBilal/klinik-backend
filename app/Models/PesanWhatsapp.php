<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu pesan WhatsApp template (outbox) — PRD BK-06, CR-01. */
#[Table('pesan_whatsapps')]
#[Fillable([
    'cabang_id', 'pasien_id', 'appointment_id', 'kunjungan_id', 'jenis', 'no_tujuan', 'template', 'parameter', 'pratinjau', 'status',
    'percobaan', 'wa_message_id', 'error', 'balasan', 'terkirim_at', 'dibaca_at', 'dibalas_at',
])]
class PesanWhatsapp extends Model
{
    public const JENIS = ['reminder_h1', 'reminder_2jam', 'followup_h1', 'followup_h7'];

    protected function casts(): array
    {
        return [
            'parameter' => 'array',
            'percobaan' => 'integer',
            'terkirim_at' => 'datetime',
            'dibaca_at' => 'datetime',
            'dibalas_at' => 'datetime',
        ];
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class)->withoutGlobalScope('cabang');
    }
}
