<?php

namespace App\Models;

use App\Enums\JenisPersetujuanData;
use App\Enums\StatusPersetujuanData;
use App\Enums\StatusPersetujuanFoto;
use App\Models\Concerns\Auditable;
use App\Services\NomorUrutService;
use Database\Factories\PasienFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master pasien milik pusat (lintas cabang). Hapus = soft delete. Hanya identitas & kontak; data klinis (alergi, Fitzpatrick,
 * hamil/menyusui, riwayat) ada di `klinis` & `alergis` agar terpisah aksesnya (PS-03), persetujuan UU PDP di `persetujuanDatas` (PS-04).
 */
#[Table('pasiens')]
#[Appends(['umur'])]
#[Hidden(['no_hp_digit'])]
#[Fillable([
    'nik', 'no_bpjs', 'nama', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir',
    'golongan_darah', 'alamat', 'no_hp', 'pekerjaan',
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

        static::saving(function (Pasien $pasien) {
            if ($pasien->isDirty('no_hp')) {
                $pasien->no_hp_digit = static::normalkanHp($pasien->no_hp);
            }
        });
    }

    /** "+62 812-3456-7890" → "081234567890"; null bila kosong (PS-02). */
    public static function normalkanHp(?string $hp): ?string
    {
        $digit = preg_replace('/\D/', '', (string) $hp);

        if ($digit === '') {
            return null;
        }

        return str_starts_with($digit, '62') ? '0'.substr($digit, 2) : $digit;
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

    /** Persetujuan foto klinis yang sedang berlaku (FT-04); null = belum/tidak menyetujui. */
    public function persetujuanFotoAktif(): HasOne
    {
        return $this->hasOne(PersetujuanFoto::class)
            ->where('persetujuan_fotos.status', StatusPersetujuanFoto::Berlaku->value)
            ->orderByDesc('persetujuan_fotos.id');
    }

    /**
     * Pasien yang opt-in marketing (dasar broadcast CR-03): punya persetujuan `marketing` yang berlaku, opsional untuk saluran tertentu
     * (`whatsapp`/`sms`/`email`/`telepon`, kolom `kanal`). Menolak promosi = tidak ada persetujuan marketing berlaku (F1-10).
     */
    public function scopeOptInMarketing(Builder $query, ?string $kanal = null): void
    {
        $query->whereHas('persetujuanDatas', fn ($q) => $q->where('jenis', JenisPersetujuanData::Marketing->value)
            ->where('status', StatusPersetujuanData::Berlaku->value)
            ->when($kanal, fn ($w) => $w->whereJsonContains('kanal', $kanal)));
    }

    /** Kunjungan di cabang aktif. Lintas cabang: `kunjungans()->withoutGlobalScope('cabang')`. */
    public function kunjungans(): HasMany
    {
        return $this->hasMany(Kunjungan::class);
    }

    /** Seluruh riwayat kondisi odontogram (lintas cabang, termasuk yang sudah diakhiri). */
    public function odontogramKondisis(): HasMany
    {
        return $this->hasMany(OdontogramKondisi::class);
    }

    public function rencanaPerawatans(): HasMany
    {
        return $this->hasMany(RencanaPerawatan::class);
    }

    /** Paket multi-sesi yang dibeli pasien (TR-02). */
    public function paketPasiens(): HasMany
    {
        return $this->hasMany(PaketPasien::class);
    }

    /** Profil klinis (PS-03): Fitzpatrick, hamil/menyusui, riwayat obat & penyakit. Hanya untuk pemegang rme.lihat. */
    public function klinis(): HasOne
    {
        return $this->hasOne(PasienKlinis::class);
    }

    /** Alergi terstruktur (PS-03). Hanya untuk pemegang rme.lihat (dan farmasi lewat resep). */
    public function alergis(): HasMany
    {
        return $this->hasMany(PasienAlergi::class)->orderBy('id');
    }

    /** Persetujuan data pribadi UU PDP (PS-04): pemrosesan & opt-in marketing, termasuk riwayat. */
    public function persetujuanDatas(): HasMany
    {
        return $this->hasMany(PersetujuanData::class);
    }

    /** Kolom turunan tidak perlu tercatat sebagai perubahan tersendiri. */
    public function auditAbaikan(): array
    {
        return ['no_hp_digit'];
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
