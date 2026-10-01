<?php

namespace App\Services;

use App\Enums\JenisPotongan;
use App\Enums\StatusTagihan;
use App\Models\Promo;
use App\Models\PromoPemakaian;
use App\Models\Tagihan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voucher & kode promo (PRD TR-06): dipasang ke tagihan yang belum dibayar, diperiksa ulang saat bayar (kuota bisa habis
 * di antaranya), dicatat sebagai pemakaian saat lunas, dan dikembalikan kuotanya saat tagihan direfund.
 *
 * Syarat: aktif, dalam periode `mulai`–`berakhir` (tanggal hari ini), cabang tagihan termasuk `cabang_ids` (kosong = semua),
 * total tagihan ≥ `min_transaksi`, kuota total & per pasien belum habis, dan ada item yang memenuhi syarat (treatment/paket
 * tertentu, atau semua item). Potongan dihitung dari nilai item yang memenuhi syarat saja.
 */
class PromoService
{
    public function __construct(private KasirService $kasir) {}

    /** Pasang kode promo ke tagihan belum bayar; potongan & grand total (perkiraan) diperbarui. */
    public function terapkan(Tagihan $tagihan, string $kode): Tagihan
    {
        $this->pastikanBelumBayar($tagihan);

        $promo = Promo::where('kode', self::normalKode($kode))->first();
        if (! $promo) {
            throw ValidationException::withMessages(['kode' => 'Kode promo tidak ditemukan.']);
        }

        $potongan = $this->hitung($promo, $tagihan);

        return $this->simpanPotongan($tagihan, $promo->id, $potongan);
    }

    public function lepas(Tagihan $tagihan): Tagihan
    {
        $this->pastikanBelumBayar($tagihan);

        return $this->simpanPotongan($tagihan, null, 0);
    }

    /**
     * Potongan promo untuk tagihan ini; 422 `kode` bila syarat tidak terpenuhi.
     * `$kunci = true` mengunci baris promo (dipakai saat bayar agar kuota terakhir tidak terpakai dua kali).
     */
    public function hitung(Promo $promo, Tagihan $tagihan, bool $kunci = false): int
    {
        if ($kunci) {
            $promo = Promo::withTrashed()->whereKey($promo->id)->lockForUpdate()->firstOrFail();
        }

        $tolak = fn (string $pesan) => throw ValidationException::withMessages(['kode' => $pesan]);
        $hariIni = today();

        if (! $promo->is_active || $promo->trashed()) {
            $tolak("Kode {$promo->kode} tidak aktif.");
        }
        if ($promo->mulai->gt($hariIni)) {
            $tolak("Kode {$promo->kode} baru berlaku mulai {$promo->mulai->translatedFormat('d M Y')}.");
        }
        if ($promo->berakhir && $promo->berakhir->lt($hariIni)) {
            $tolak("Kode {$promo->kode} sudah berakhir {$promo->berakhir->translatedFormat('d M Y')}.");
        }
        if (! empty($promo->cabang_ids) && ! in_array((int) $tagihan->cabang_id, array_map('intval', $promo->cabang_ids), true)) {
            $tolak("Kode {$promo->kode} tidak berlaku di cabang ini.");
        }
        if ($tagihan->total < $promo->min_transaksi) {
            $tolak("Kode {$promo->kode} berlaku untuk transaksi minimal Rp ".number_format($promo->min_transaksi, 0, ',', '.').'.');
        }

        $dipakai = fn () => PromoPemakaian::where('promo_id', $promo->id)->whereNull('dibatalkan_at')->where('tagihan_id', '!=', $tagihan->id);
        if ($promo->kuota !== null && $dipakai()->count() >= $promo->kuota) {
            $tolak("Kuota kode {$promo->kode} sudah habis.");
        }
        if ($promo->kuota_per_pasien !== null) {
            $pasienId = $tagihan->pasienId();
            if (! $pasienId) {
                $tolak("Kode {$promo->kode} hanya untuk pasien terdaftar.");
            }
            if ($dipakai()->where('pasien_id', $pasienId)->count() >= $promo->kuota_per_pasien) {
                $tolak("Pasien ini sudah memakai kode {$promo->kode} sebanyak batas per pasien.");
            }
        }

        $dasar = $this->nilaiMemenuhiSyarat($promo, $tagihan);
        if ($dasar <= 0) {
            $tolak("Tidak ada item tagihan yang mendapat kode {$promo->kode}.");
        }

        $potongan = $promo->jenis === JenisPotongan::Persen
            ? (int) floor($dasar * $promo->nilai / 100)
            : $promo->nilai;

        if ($promo->jenis === JenisPotongan::Persen && $promo->maks_potongan !== null) {
            $potongan = min($potongan, $promo->maks_potongan);
        }

        return min($potongan, $dasar);
    }

    /** Tagihan lunas: catat pemakaian kode promo (dasar kuota). */
    public function catatPemakaian(Tagihan $tagihan): void
    {
        if (! $tagihan->promo_id || $tagihan->diskon_promo <= 0) {
            return;
        }

        PromoPemakaian::updateOrCreate(['tagihan_id' => $tagihan->id], [
            'promo_id' => $tagihan->promo_id,
            'pasien_id' => $tagihan->pasienId(),
            'cabang_id' => $tagihan->cabang_id,
            'potongan' => $tagihan->diskon_promo,
            'dipakai_at' => now(),
            'dibatalkan_at' => null,
        ]);
    }

    /** Tagihan direfund: pemakaian dibatalkan → kuota kembali. */
    public function batalkanPemakaian(Tagihan $tagihan): void
    {
        PromoPemakaian::where('tagihan_id', $tagihan->id)->whereNull('dibatalkan_at')->get()
            ->each->update(['dibatalkan_at' => now()]);
    }

    public static function normalKode(string $kode): string
    {
        return strtoupper(trim($kode));
    }

    /** Subtotal item yang memenuhi syarat promo (semua item bila promo tidak dibatasi treatment/paket). */
    private function nilaiMemenuhiSyarat(Promo $promo, Tagihan $tagihan): int
    {
        $items = $tagihan->items()->get(['id', 'tindakan_id', 'paket_id', 'subtotal']);

        if ($promo->semuaItem()) {
            return (int) $items->sum('subtotal');
        }

        $tindakan = array_map('intval', $promo->tindakan_ids ?? []);
        $paket = array_map('intval', $promo->paket_ids ?? []);

        return (int) $items->filter(fn ($i) => ($i->tindakan_id && in_array($i->tindakan_id, $tindakan, true))
            || ($i->paket_id && in_array($i->paket_id, $paket, true)))->sum('subtotal');
    }

    private function simpanPotongan(Tagihan $tagihan, ?int $promoId, int $potongan): Tagihan
    {
        return DB::transaction(function () use ($tagihan, $promoId, $potongan) {
            // Diskon manual diberikan saat bayar; perkiraan grand total di sini hanya memperhitungkan promo.
            $grandTotal = $this->kasir->hitungGrandTotal($tagihan->total, $potongan, $tagihan->pajak_persen);
            $tagihan->update([
                'promo_id' => $promoId,
                'diskon_promo' => $potongan,
                'pajak' => $grandTotal - ($tagihan->total - $potongan),
                'grand_total' => $grandTotal,
            ]);

            return $tagihan;
        });
    }

    private function pastikanBelumBayar(Tagihan $tagihan): void
    {
        if ($tagihan->status !== StatusTagihan::BelumBayar) {
            throw ValidationException::withMessages(['kode' => 'Kode promo hanya bisa dipasang pada tagihan yang belum dibayar.']);
        }
    }
}
