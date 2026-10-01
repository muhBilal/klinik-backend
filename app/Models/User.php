<?php

namespace App\Models;

use App\Enums\Izin;
use App\Models\Concerns\Auditable;
use App\Services\PengaturanService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * `role` = kode peran (`perans.kode`). Hak akses ditentukan izin peran: `punyaIzin(Izin::X)`.
 * `cabang_id` null = boleh mengakses semua cabang.
 */
#[Fillable(['name', 'email', 'avatar', 'theme', 'password', 'role', 'poli_id', 'cabang_id', 'sip', 'sip_berlaku_sampai', 'str', 'str_berlaku_sampai', 'nik', 'is_active'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step', 'peran'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** @var list<string>|null izin efektif, dihitung sekali per instance */
    private ?array $izinMemo = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'sip_berlaku_sampai' => 'date:Y-m-d',
            'str_berlaku_sampai' => 'date:Y-m-d',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
            'theme' => 'array',
        ];
    }

    public function poli(): BelongsTo
    {
        return $this->belongsTo(Poli::class)->withTrashed();
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class)->withTrashed();
    }

    public function peran(): BelongsTo
    {
        return $this->belongsTo(Peran::class, 'role', 'kode');
    }

    /** @return list<string> */
    public function izin(): array
    {
        return $this->izinMemo ??= $this->peran?->izin ?? [];
    }

    /** Punya salah satu izin yang disebut. */
    public function punyaIzin(Izin|string ...$izin): bool
    {
        $milik = $this->izin();

        foreach ($izin as $kode) {
            if (in_array($kode instanceof Izin ? $kode->value : $kode, $milik, true)) {
                return true;
            }
        }

        return false;
    }

    /** Muat ulang izin setelah peran/izin diubah pada instance yang sama. */
    public function lupakanIzin(): void
    {
        $this->izinMemo = null;
        $this->unsetRelation('peran');
    }

    /**
     * Tenaga medis yang tercatat sebagai dokter pada kunjungan (bukan administrator yang kebetulan punya akses penuh).
     */
    public function tercatatSebagaiDokter(): bool
    {
        return ! $this->peran?->akses_penuh && $this->punyaIzin(Izin::PemeriksaanDokter);
    }

    /**
     * Punya nomor SIP yang belum kedaluwarsa (UU 17/2023). Syarat menandatangani rekam medis & addendum.
     * Tanggal berlaku kosong = dianggap aktif (belum dicatat).
     */
    public function sipAktif(): bool
    {
        return filled($this->sip) && ($this->sip_berlaku_sampai === null || ! $this->sip_berlaku_sampai->isBefore(today()));
    }

    /** Petugas yang boleh tercatat melakukan tindakan: aktif dan memegang izin pelayanan/tindakan. */
    public function scopePetugasMedis(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereHas('peran', fn ($p) => $p->where('akses_penuh', false)->whereHas('izins', fn ($q) => $q->whereIn('izin', [
                Izin::PemeriksaanDokter->value, Izin::PemeriksaanVital->value, Izin::RmeTindakan->value,
            ])));
    }

    public function aksesSemuaCabang(): bool
    {
        return $this->cabang_id === null;
    }

    public function twoFactorAktif(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /** Peran user termasuk daftar `keamanan.wajib_2fa`. */
    public function wajib2fa(): bool
    {
        return in_array($this->role, (array) app(PengaturanService::class)->get('keamanan.wajib_2fa'), true);
    }

    /** Dokter aktif = user aktif yang perannya memegang izin pemeriksaan.dokter. */
    public function scopeDokter(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereHas('peran.izins', fn ($q) => $q->where('izin', Izin::PemeriksaanDokter->value));
    }

    public function auditLabel(): ?string
    {
        return $this->email;
    }
}
