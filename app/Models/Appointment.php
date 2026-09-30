<?php

namespace App\Models;

use App\Enums\StatusAppointment;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Booking (PRD BK-01). Terpisah dari `kunjungans`: bisa bertanggal kapan saja dan baru menjadi
 * kunjungan saat check-in (`kunjungan_id` terisi).
 */
#[Table('appointments')]
#[Fillable([
    'cabang_id', 'no_booking', 'pasien_id', 'poli_id', 'petugas_id', 'mulai_at', 'selesai_at',
    'status', 'catatan', 'kunjungan_id', 'dikonfirmasi_at', 'checkin_at', 'alasan_batal', 'created_by',
])]
class Appointment extends Model
{
    use Auditable, DalamCabang, SoftDeletes;

    protected function casts(): array
    {
        return [
            'mulai_at' => 'datetime',
            'selesai_at' => 'datetime',
            'status' => StatusAppointment::class,
            'dikonfirmasi_at' => 'datetime',
            'checkin_at' => 'datetime',
        ];
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function poli(): BelongsTo
    {
        return $this->belongsTo(Poli::class)->withTrashed();
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'petugas_id')->withTrashed();
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    public function tindakans(): HasMany
    {
        return $this->hasMany(AppointmentTindakan::class);
    }

    public function sumberDayas(): BelongsToMany
    {
        return $this->belongsToMany(SumberDaya::class, 'appointment_sumber_dayas')->withoutGlobalScope('cabang');
    }

    /** Booking yang masih memesan slot — dipakai saat memeriksa bentrok. */
    public function scopeMemesanSlot(Builder $query): void
    {
        $query->whereIn('status', StatusAppointment::aktif());
    }

    /** Booking yang jadwalnya beririsan dengan rentang `[$mulai, $selesai)`. */
    public function scopeBeririsan(Builder $query, string $mulai, string $selesai): void
    {
        $query->where('mulai_at', '<', $selesai)->where('selesai_at', '>', $mulai);
    }

    public function auditLabel(): ?string
    {
        return $this->no_booking;
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }
}
