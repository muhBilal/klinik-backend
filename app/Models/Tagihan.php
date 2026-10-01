<?php

namespace App\Models;

use App\Enums\MetodeBayar;
use App\Enums\StatusTagihan;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\DalamCabang;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('tagihans')]
#[Fillable([
    'cabang_id', 'no_tagihan', 'kunjungan_id', 'pasien_id', 'total', 'diskon', 'diskon_disetujui_oleh', 'promo_id', 'diskon_promo', 'pajak', 'pajak_persen',
    'grand_total', 'status', 'keterangan', 'metode_bayar', 'dibayar', 'kembalian', 'kasir_id', 'shift_id',
    'dibayar_at', 'dibatalkan_at', 'dibatalkan_oleh', 'alasan_batal',
])]
class Tagihan extends Model
{
    use Auditable, DalamCabang, SoftDeletes;

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'diskon' => 'integer',
            'diskon_promo' => 'integer',
            'pajak' => 'integer',
            'pajak_persen' => 'integer',
            'grand_total' => 'integer',
            'dibayar' => 'integer',
            'kembalian' => 'integer',
            'status' => StatusTagihan::class,
            'metode_bayar' => MetodeBayar::class,
            'dibayar_at' => 'datetime',
            'dibatalkan_at' => 'datetime',
        ];
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TagihanItem::class);
    }

    public function pembayarans(): HasMany
    {
        return $this->hasMany(Pembayaran::class);
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftKas::class, 'shift_id')->withoutGlobalScope('cabang');
    }

    /** Voucher / kode promo yang dipasang (TR-06); potongannya di `diskon_promo`. */
    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class)->withTrashed();
    }

    /** Paket yang dijual lewat tagihan ini (TR-02); aktif saat tagihan lunas. */
    public function paketPasiens(): HasMany
    {
        return $this->hasMany(PaketPasien::class);
    }

    /** Pasien tagihan: langsung (tagihan mandiri) atau lewat kunjungan. */
    public function pasienId(): ?int
    {
        return $this->pasien_id ?? $this->kunjungan?->pasien_id;
    }

    /** Total pembayaran yang masih berlaku (refund tidak dihitung). */
    public function totalDibayar(): int
    {
        return (int) $this->pembayarans()->berlaku()->sum('jumlah');
    }

    public function sisaTagihan(): int
    {
        return max(0, $this->grand_total - $this->totalDibayar());
    }

    public function kasir(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kasir_id')->withTrashed();
    }

    /** Atasan yang menyetujui diskon di atas batas peran kasir (BL-02). */
    public function penyetujuDiskon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diskon_disetujui_oleh')->withTrashed();
    }

    public function auditLabel(): ?string
    {
        return $this->no_tagihan;
    }

    public function auditPasienId(): ?int
    {
        return $this->pasienId();
    }
}
