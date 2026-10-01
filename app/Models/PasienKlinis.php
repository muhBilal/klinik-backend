<?php

namespace App\Models;

use App\Enums\StatusKehamilan;
use App\Enums\TipeKulitFitzpatrick;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Profil klinis pasien (PRD PS-03): tipe kulit Fitzpatrick, status hamil/menyusui, riwayat obat & penyakit. Satu baris per pasien,
 * dibuat saat pertama diisi. Data kesehatan (data pribadi spesifik UU PDP): hanya untuk pemegang `rme.lihat`, setiap perubahan diaudit.
 */
#[Table('pasien_klinis')]
#[Fillable(['pasien_id', 'fitzpatrick', 'status_kehamilan', 'status_kehamilan_at', 'riwayat_obat', 'riwayat_penyakit', 'diperbarui_oleh'])]
class PasienKlinis extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'fitzpatrick' => TipeKulitFitzpatrick::class,
            'status_kehamilan' => StatusKehamilan::class,
            'status_kehamilan_at' => 'date:Y-m-d',
        ];
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function pembaru(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diperbarui_oleh')->withTrashed();
    }

    public function auditLabel(): ?string
    {
        return 'Data klinis pasien';
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }
}
