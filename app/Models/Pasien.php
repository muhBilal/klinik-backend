<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\NomorUrutService;
use Database\Factories\PasienFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master pasien milik pusat (lintas cabang). Hapus = soft delete.
 */
#[Table('pasiens')]
#[Appends(['umur'])]
#[Fillable([
    'nik', 'no_bpjs', 'nama', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir',
    'golongan_darah', 'alamat', 'no_hp', 'pekerjaan', 'alergi',
])]
class Pasien extends Model
{
    /** @use HasFactory<PasienFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Pasien $pasien) {
            $pasien->no_rm ??= app(NomorUrutService::class)->noRekamMedis();
        });
    }

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date:Y-m-d',
        ];
    }

    protected function umur(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->tanggal_lahir) {
                return null;
            }

            $diff = $this->tanggal_lahir->diff(now());

            return $diff->y > 0 ? "{$diff->y} th" : "{$diff->m} bln";
        });
    }

    /** Kunjungan di cabang aktif. Lintas cabang: `kunjungans()->withoutGlobalScope('cabang')`. */
    public function kunjungans(): HasMany
    {
        return $this->hasMany(Kunjungan::class);
    }

    public function auditLabel(): ?string
    {
        return "{$this->no_rm} · {$this->nama}";
    }

    public function auditPasienId(): ?int
    {
        return $this->id;
    }
}
