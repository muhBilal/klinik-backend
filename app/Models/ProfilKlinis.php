<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Profil klinis pasien (PRD PS-03): tipe kulit, status kehamilan, riwayat. Data klinis → hanya untuk `rme.lihat`. */
#[Table('profil_klinis')]
#[Fillable(['pasien_id', 'fitzpatrick', 'status_kehamilan', 'hpht', 'status_kehamilan_at', 'riwayat_obat', 'riwayat_penyakit', 'diperbarui_oleh'])]
class ProfilKlinis extends Model
{
    use Auditable;

    public const STATUS_KEHAMILAN = ['tidak', 'hamil', 'menyusui'];

    protected function casts(): array
    {
        return [
            'fitzpatrick' => 'integer',
            'hpht' => 'date:Y-m-d',
            'status_kehamilan_at' => 'datetime',
        ];
    }

    public function pembaru(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diperbarui_oleh')->withTrashed();
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }
}
