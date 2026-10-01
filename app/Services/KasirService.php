<?php

namespace App\Services;

use App\Enums\MetodeBayar;
use App\Enums\StatusKunjungan;
use App\Enums\StatusTagihan;
use App\Models\PaketPasien;
use App\Models\Pembayaran;
use App\Models\ShiftKas;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kasir: shift kas, split payment, batas diskon per peran, void & refund
 * (PRD BL-02, BL-03, BL-05, BL-06; temuan teknis 8.3 #7).
 */
class KasirService
{
    public function __construct(private PengaturanService $pengaturan) {}

    /** Shift kasir yang masih terbuka di cabang ini, bila ada. */
    public function shiftTerbuka(int $cabangId, User $kasir): ?ShiftKas
    {
        return ShiftKas::withoutGlobalScope('cabang')
            ->where('cabang_id', $cabangId)->where('kasir_id', $kasir->id)
            ->whereNull('ditutup_at')
            ->first();
    }

    public function bukaShift(int $cabangId, User $kasir, int $modalAwal): ShiftKas
    {
        if ($this->shiftTerbuka($cabangId, $kasir)) {
            throw ValidationException::withMessages(['shift' => 'Masih ada shift yang belum ditutup.']);
        }

        return ShiftKas::create([
            'cabang_id' => $cabangId,
            'kasir_id' => $kasir->id,
            'dibuka_at' => now(),
            'modal_awal' => $modalAwal,
        ]);
    }

    /**
     * Tutup shift; `selisih` = kas fisik dikurangi (modal awal + tunai masuk).
     * Selisih negatif berarti uang kurang.
     */
    public function tutupShift(ShiftKas $shift, int $kasFisik, ?string $catatan): ShiftKas
    {
        return DB::transaction(function () use ($shift, $kasFisik, $catatan) {
            if (! $shift->terbuka()) {
                throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
            }

            $tunai = (int) $shift->pembayarans()->berlaku()->where('metode', MetodeBayar::Tunai)->sum('jumlah');
            $refundTunai = $this->refundPaket($shift, MetodeBayar::Tunai);

            $shift->update([
                'ditutup_at' => now(),
                'kas_fisik' => $kasFisik,
                'selisih' => $kasFisik - ($shift->modal_awal + $tunai - $refundTunai),
                'catatan' => $catatan,
            ]);

            return $shift;
        });
    }

    /** Rekap shift per metode bayar, untuk layar tutup shift & cetak. */
    public function rekapShift(ShiftKas $shift): array
    {
        $perMetode = $shift->pembayarans()->berlaku()
            ->selectRaw('metode, COUNT(*) AS jumlah_transaksi, SUM(jumlah) AS total')
            ->groupBy('metode')->get()
            ->map(fn ($b) => [
                'metode' => $b->metode->value,
                'jumlah_transaksi' => (int) $b->jumlah_transaksi,
                'total' => (int) $b->total,
            ])->values();

        $refund = (int) $shift->pembayarans()->whereNotNull('dikembalikan_at')->sum('jumlah');
        $tunai = (int) ($perMetode->firstWhere('metode', MetodeBayar::Tunai->value)['total'] ?? 0);
        // Pengembalian sisa paket (TR-02) = uang keluar di shift ini; yang tunai mengurangi kas seharusnya.
        $refundPaket = $this->refundPaket($shift);
        $refundPaketTunai = $this->refundPaket($shift, MetodeBayar::Tunai);

        return [
            'per_metode' => $perMetode,
            'total' => (int) $perMetode->sum('total'),
            'total_refund' => $refund + $refundPaket,
            'refund_paket' => $refundPaket,
            'kas_seharusnya' => $shift->modal_awal + $tunai - $refundPaketTunai,
        ];
    }

    private function refundPaket(ShiftKas $shift, ?MetodeBayar $metode = null): int
    {
        return (int) PaketPasien::where('refund_shift_id', $shift->id)
            ->when($metode, fn ($q) => $q->where('refund_metode', $metode))
            ->sum('refund_nominal');
    }

    /**
     * Bayar tagihan, boleh beberapa metode sekaligus (BL-03). Kode promo yang terpasang diperiksa ulang & dihitung ulang
     * (kuota bisa habis sejak dipasang) lalu dicatat pemakaiannya (TR-06); paket yang dijual lewat tagihan ini aktif (TR-02).
     *
     * @param  list<array{metode: string, jumlah: int, referensi?: string}>  $pembayarans
     * @param  int  $diskon  diskon manual nominal; dibatasi oleh batas peran (BL-02), di luar potongan promo
     * @param  string  $kunciNominal  field tujuan pesan error nominal; klien lama memakai `dibayar`
     */
    public function bayar(Tagihan $tagihan, array $pembayarans, int $diskon, User $kasir, string $kunciNominal = 'pembayarans'): Tagihan
    {
        return DB::transaction(function () use ($tagihan, $pembayarans, $diskon, $kasir, $kunciNominal) {
            $tagihan = Tagihan::withoutGlobalScope('cabang')->whereKey($tagihan->id)->lockForUpdate()->firstOrFail();

            if ($tagihan->status !== StatusTagihan::BelumBayar) {
                throw ValidationException::withMessages(['status' => 'Tagihan ini sudah dibayar atau dibatalkan.']);
            }

            $diskonPromo = $tagihan->promo_id
                ? app(PromoService::class)->hitung($tagihan->promo()->firstOrFail(), $tagihan, kunci: true)
                : 0;

            if ($diskon + $diskonPromo > $tagihan->total) {
                throw ValidationException::withMessages(['diskon' => 'Diskon (termasuk potongan promo) tidak boleh melebihi total tagihan.']);
            }

            $this->pastikanDiskonDiizinkan($tagihan, $diskon, $kasir);

            $grandTotal = $this->hitungGrandTotal($tagihan->total, $diskon + $diskonPromo, $tagihan->pajak_persen);
            $baris = $this->normalkanPembayaran($pembayarans, $kunciNominal);
            $dibayar = (int) $baris->sum('jumlah');

            if ($dibayar < $grandTotal) {
                throw ValidationException::withMessages([$kunciNominal => 'Nominal pembayaran kurang dari total tagihan.']);
            }

            // Hanya tunai yang boleh berlebih (kembalian); non-tunai harus pas.
            $nonTunai = (int) $baris->where('metode', '!=', MetodeBayar::Tunai->value)->sum('jumlah');

            if ($nonTunai > $grandTotal) {
                throw ValidationException::withMessages([$kunciNominal => 'Pembayaran non-tunai tidak boleh melebihi tagihan.']);
            }

            $shift = $this->shiftTerbuka($tagihan->cabang_id, $kasir);

            $tagihan->update([
                'diskon' => $diskon,
                'diskon_promo' => $diskonPromo,
                'pajak' => $grandTotal - ($tagihan->total - $diskon - $diskonPromo),
                'grand_total' => $grandTotal,
                'metode_bayar' => $baris->count() === 1 ? $baris->first()['metode'] : null,
                'dibayar' => $dibayar,
                'kembalian' => $dibayar - $grandTotal,
                'status' => StatusTagihan::Lunas,
                'kasir_id' => $kasir->id,
                'shift_id' => $shift?->id,
                'dibayar_at' => now(),
            ]);

            foreach ($baris as $b) {
                $tagihan->pembayarans()->create([
                    'shift_id' => $shift?->id,
                    'metode' => $b['metode'],
                    'jumlah' => $b['jumlah'],
                    'referensi' => $b['referensi'] ?? null,
                    'kasir_id' => $kasir->id,
                    'dibayar_at' => now(),
                ]);
            }

            app(PromoService::class)->catatPemakaian($tagihan);
            app(PaketService::class)->aktifkanDariTagihan($tagihan);

            // Kunjungan selesai hanya bila semua tagihannya sudah lunas/batal.
            if ($kunjungan = $tagihan->kunjungan) {
                $masihAda = $kunjungan->tagihans()
                    ->where('status', StatusTagihan::BelumBayar)
                    ->exists();

                if (! $masihAda) {
                    $kunjungan->update(['status' => StatusKunjungan::Selesai]);
                }
            }

            return $tagihan;
        });
    }

    /** Batalkan (void) tagihan yang belum dibayar — BL-06, temuan 8.3 #7. */
    public function batal(Tagihan $tagihan, string $alasan, User $user): Tagihan
    {
        return DB::transaction(function () use ($tagihan, $alasan, $user) {
            $tagihan = Tagihan::withoutGlobalScope('cabang')->whereKey($tagihan->id)->lockForUpdate()->firstOrFail();

            if ($tagihan->status !== StatusTagihan::BelumBayar) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya tagihan yang belum dibayar yang bisa dibatalkan. Tagihan lunas gunakan refund.',
                ]);
            }

            $tagihan->update([
                'status' => StatusTagihan::Batal,
                'dibatalkan_at' => now(),
                'dibatalkan_oleh' => $user->id,
                'alasan_batal' => $alasan,
            ]);

            app(PaketService::class)->batalDariTagihan($tagihan);

            return $tagihan;
        });
    }

    /**
     * Refund seluruh pembayaran tagihan yang sudah lunas (BL-06).
     * Tagihan kembali ke status batal dengan jejak siapa & alasannya.
     */
    public function refund(Tagihan $tagihan, string $alasan, User $user): Tagihan
    {
        return DB::transaction(function () use ($tagihan, $alasan, $user) {
            $tagihan = Tagihan::withoutGlobalScope('cabang')->whereKey($tagihan->id)->lockForUpdate()->firstOrFail();

            if ($tagihan->status !== StatusTagihan::Lunas) {
                throw ValidationException::withMessages(['status' => 'Hanya tagihan lunas yang bisa direfund.']);
            }

            // Paket yang dijual lewat tagihan ini hanya bisa direfund penuh bila belum dipakai (TR-02); kuota promo kembali.
            app(PaketService::class)->refundPenuhDariTagihan($tagihan, $alasan, $user);
            app(PromoService::class)->batalkanPemakaian($tagihan);

            // Per model agar setiap refund tercatat di audit log.
            foreach ($tagihan->pembayarans()->berlaku()->get() as $pembayaran) {
                $pembayaran->update([
                    'dikembalikan_at' => now(),
                    'dikembalikan_oleh' => $user->id,
                    'alasan_refund' => $alasan,
                ]);
            }

            $tagihan->update([
                'status' => StatusTagihan::Batal,
                'dibatalkan_at' => now(),
                'dibatalkan_oleh' => $user->id,
                'alasan_batal' => $alasan,
            ]);

            if ($kunjungan = $tagihan->kunjungan) {
                $kunjungan->update(['status' => StatusKunjungan::MenungguPembayaran]);
            }

            return $tagihan;
        });
    }

    /** Grand total setelah diskon dan pajak (AD-04). Pajak dihitung dari nilai setelah diskon. */
    public function hitungGrandTotal(int $total, int $diskon, int $pajakPersen): int
    {
        $setelahDiskon = $total - $diskon;

        return $setelahDiskon + (int) round($setelahDiskon * $pajakPersen / 100);
    }

    /** Tarif pajak berlaku (persen) dari pengaturan klinik. */
    public function pajakPersen(): int
    {
        return (int) $this->pengaturan->get('keuangan.pajak_persen');
    }

    /**
     * Batas diskon per peran (BL-02): persen maksimum dari total, dari pengaturan
     * `keuangan.batas_diskon_persen` = {kode_peran: persen}.
     *
     * Peran berakses penuh dan peran yang tidak tercantum tidak dibatasi — batas bersifat opt-in
     * agar klinik yang belum mengaturnya tetap berjalan seperti sebelumnya. Persen 0 = dilarang.
     */
    private function pastikanDiskonDiizinkan(Tagihan $tagihan, int $diskon, User $kasir): void
    {
        if ($diskon === 0 || $kasir->peran?->akses_penuh) {
            return;
        }

        $batas = $this->pengaturan->get('keuangan.batas_diskon_persen') ?? [];

        if (! array_key_exists($kasir->role, $batas)) {
            return;
        }

        $persen = (int) $batas[$kasir->role];
        $maks = (int) floor($tagihan->total * $persen / 100);

        if ($diskon > $maks) {
            throw ValidationException::withMessages([
                'diskon' => $persen === 0
                    ? 'Peran Anda tidak boleh memberi diskon. Minta persetujuan atasan.'
                    : "Diskon melebihi batas peran Anda ({$persen}% = Rp ".number_format($maks, 0, ',', '.').').',
            ]);
        }
    }

    /**
     * @param  list<array{metode: string, jumlah: int, referensi?: string}>  $pembayarans
     * @return Collection<int, array{metode: string, jumlah: int, referensi?: string}>
     */
    private function normalkanPembayaran(array $pembayarans, string $kunciNominal): Collection
    {
        $baris = collect($pembayarans)->filter(fn ($b) => (int) $b['jumlah'] > 0)->values();

        if ($baris->isEmpty()) {
            throw ValidationException::withMessages([$kunciNominal => 'Masukkan minimal satu pembayaran.']);
        }

        return $baris;
    }
}
