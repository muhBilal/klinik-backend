<?php

namespace App\Models;

use App\Enums\MetodeBayar;
use App\Enums\StatusPaketPasien;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Paket yang dibeli pasien (PRD TR-02). Milik pasien (lintas cabang untuk dibaca); dipakai di cabang mana pun kecuali
 * `lintas_cabang = false`. Sisa sesi dihitung dari tindakan kunjungan yang memakainya (`PaketService::ringkas`).
 */
#[Table('paket_pasiens')]
#[Fillable([
    'no_paket', 'pasien_id', 'paket_id', 'cabang_id', 'tagihan_id', 'kunjungan_id', 'nama', 'harga', 'nilai', 'status', 'lintas_cabang',
    'masa_berlaku_hari', 'aktif_at', 'berlaku_sampai', 'catatan', 'dibuat_oleh',
    'dialihkan_dari_id', 'dialihkan_at', 'dialihkan_oleh', 'alasan_alih',
    'refund_nominal', 'refund_metode', 'refund_referensi', 'refund_shift_id', 'direfund_at', 'direfund_oleh', 'alasan_refund',
])]
class PaketPasien extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'status' => StatusPaketPasien::class,
            'harga' => 'integer',
            'nilai' => 'integer',
            'lintas_cabang' => 'boolean',
            'masa_berlaku_hari' => 'integer',
            'aktif_at' => 'datetime',
            'berlaku_sampai' => 'date:Y-m-d',
            'dialihkan_at' => 'datetime',
            'refund_nominal' => 'integer',
            'refund_metode' => MetodeBayar::class,
            'direfund_at' => 'datetime',
            'pasien_id' => 'integer',
            'cabang_id' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaketPasienItem::class)->orderBy('id');
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function paket(): BelongsTo
    {
        return $this->belongsTo(Paket::class)->withTrashed();
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class)->withTrashed();
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class)->withoutGlobalScope('cabang');
    }

    /** Pembuat: kasir (jual langsung) atau dokter/terapis yang memesankan paket dari pemeriksaan. */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh')->withTrashed();
    }

    /** Kunjungan tempat paket dipesan dokter/terapis (ditagihkan bersama tagihan kunjungan); null = dijual langsung di kasir. */
    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    public function dialihkanDari(): BelongsTo
    {
        return $this->belongsTo(self::class, 'dialihkan_dari_id');
    }

    /** Paket baru milik pasien lain yang menerima sisa sesi paket ini. */
    public function dialihkanKe(): HasOne
    {
        return $this->hasOne(self::class, 'dialihkan_dari_id');
    }

    /**
     * Status yang ditampilkan: paket aktif yang lewat masa berlaku = `kedaluwarsa`, yang sisa sesinya 0 = `habis`.
     * `$sisaSesi` dari `PaketService::ringkas` (tanpa itu hanya tanggal yang dicek).
     */
    public function statusEfektif(?int $sisaSesi = null): string
    {
        if ($this->status !== StatusPaketPasien::Aktif) {
            return $this->status->value;
        }
        if ($this->berlaku_sampai && $this->berlaku_sampai->lt(today())) {
            return StatusPaketPasien::KEDALUWARSA;
        }

        return $sisaSesi === 0 ? StatusPaketPasien::HABIS : StatusPaketPasien::Aktif->value;
    }

    public function auditLabel(): ?string
    {
        return "{$this->no_paket} · {$this->nama}";
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }
}
