<?php

namespace App\Services;

use App\Enums\StatusKunjungan;
use App\Enums\StatusPaketPasien;
use App\Enums\StatusTagihan;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\PaketPasien;
use App\Models\Tagihan;
use App\Support\Gigi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TagihanService
{
    public function __construct(
        private NomorUrutService $nomor,
        private KasirService $kasir,
    ) {}

    /**
     * Susun tagihan dari jasa konsultasi poli, tindakan, obat pada resep (BL-01), dan paket yang dipesan dokter/terapis di kunjungan
     * ini (TR-02; aktif saat tagihan lunas). Tindakan yang memakai sesi paket ditagih Rp 0 dengan keterangan nomor paket & urutan sesi.
     */
    public function buatDariKunjungan(Kunjungan $kunjungan): Tagihan
    {
        // Paket yang dipesan di kunjungan ini (belum punya tagihan) ikut ditagihkan — pasien cukup membayar sekali di kasir.
        $pesanan = PaketPasien::where('kunjungan_id', $kunjungan->id)->where('status', StatusPaketPasien::MenungguBayar)
            ->whereNull('tagihan_id')->orderBy('id')->lockForUpdate()->get();
        $items = $this->barisKunjungan($kunjungan, $pesanan);
        $total = array_sum(array_column($items, 'subtotal'));

        // Tarif pajak di-snapshot saat tagihan dibuat (AD-04); perubahan pengaturan tidak mengubah tagihan lama.
        $pajakPersen = $this->kasir->pajakPersen();
        $grandTotal = $this->kasir->hitungGrandTotal($total, 0, $pajakPersen);

        $tagihan = $kunjungan->tagihans()->create([
            'cabang_id' => $kunjungan->cabang_id,
            'no_tagihan' => $this->nomor->noTagihan(now()),
            'pasien_id' => $kunjungan->pasien_id,
            'total' => $total,
            'pajak' => $grandTotal - $total,
            'pajak_persen' => $pajakPersen,
            'grand_total' => $grandTotal,
            'status' => StatusTagihan::BelumBayar,
        ]);

        $tagihan->items()->createMany($items);
        $pesanan->each->update(['tagihan_id' => $tagihan->id]);

        return $tagihan;
    }

    /**
     * Susun ulang tagihan kunjungan yang belum dibayar dari isi kunjungan terkini (mis. pasien menolak paket yang dipesan): baris diganti,
     * total & pajak dihitung ulang (tarif pajak tetap snapshot), kode promo dihitung ulang — dilepas bila tidak lagi memenuhi syarat.
     */
    public function susunUlang(Tagihan $tagihan): Tagihan
    {
        if ($tagihan->status !== StatusTagihan::BelumBayar || ! $tagihan->kunjungan_id) {
            throw ValidationException::withMessages(['status' => 'Hanya tagihan kunjungan yang belum dibayar yang bisa disusun ulang.']);
        }

        $kunjungan = Kunjungan::withoutGlobalScope('cabang')->findOrFail($tagihan->kunjungan_id);
        $pesanan = PaketPasien::where('tagihan_id', $tagihan->id)->where('status', StatusPaketPasien::MenungguBayar)->orderBy('id')->get();
        $items = $this->barisKunjungan($kunjungan, $pesanan);

        // Per model agar perubahan baris tercatat di audit log.
        $tagihan->items()->get()->each->delete();
        $tagihan->items()->createMany($items);
        $tagihan->total = array_sum(array_column($items, 'subtotal'));

        $potongan = 0;
        if ($tagihan->promo_id) {
            try {
                $potongan = app(PromoService::class)->hitung($tagihan->promo()->firstOrFail(), $tagihan);
            } catch (ValidationException) {
                $tagihan->promo_id = null;
            }
        }
        $grandTotal = $this->kasir->hitungGrandTotal($tagihan->total, $potongan, $tagihan->pajak_persen);
        $tagihan->fill(['diskon_promo' => $potongan, 'pajak' => $grandTotal - ($tagihan->total - $potongan), 'grand_total' => $grandTotal])->save();

        return $tagihan;
    }

    /**
     * Baris tagihan kunjungan: konsultasi, tindakan (sesi paket Rp 0), obat resep, dan paket yang dipesan di kunjungan itu.
     *
     * @param  Collection<int, PaketPasien>  $pesanan
     * @return list<array<string, mixed>>
     */
    private function barisKunjungan(Kunjungan $kunjungan, Collection $pesanan): array
    {
        $kunjungan->loadMissing(['poli', 'tindakans.tindakan', 'tindakans.paketItem.paketPasien:id,no_paket', 'resep.items.obat', 'resep.items.komponens']);

        // Jasa konsultasi = treatment yang dipilih di master poli (harga cabang & komisi dokter ikut katalog). Bila dokter sudah
        // mencatat treatment itu sebagai tindakan (mis. konsultasi ×2), tidak ditagih dua kali.
        $konsultasi = $kunjungan->poli->jasaKonsultasi($kunjungan->cabang_id);
        $items = $konsultasi && ! $kunjungan->tindakans->contains('tindakan_id', $konsultasi->id) ? [[
            'kategori' => 'konsultasi',
            'tindakan_id' => $konsultasi->id,
            'deskripsi' => Str::limit($konsultasi->nama, 180, ''),
            'jumlah' => 1,
            'harga' => $konsultasi->tarif_cabang,
        ]] : [];

        foreach ($kunjungan->tindakans as $tindakan) {
            // Tindakan per gigi ditagih per gigi, mis. "Tambal gigi komposit — gigi 16 (MO)" (DG-07).
            $gigi = Gigi::format($tindakan->gigi, $tindakan->permukaan);
            $deskripsi = $gigi ? Str::limit($tindakan->tindakan->nama, 180, '')." — {$gigi}" : Str::limit($tindakan->tindakan->nama, 180, '');
            $paket = $tindakan->paketItem;

            $items[] = [
                'kategori' => 'tindakan',
                'tindakan_id' => $tindakan->tindakan_id,
                'deskripsi' => $paket ? "{$deskripsi} · paket {$paket->paketPasien->no_paket} sesi {$this->urutanSesi($tindakan)}/{$paket->jumlah_sesi}" : $deskripsi,
                'jumlah' => $tindakan->jumlah,
                'harga' => $paket ? 0 : $tindakan->tarif,
            ];
        }

        foreach ($kunjungan->resep?->items ?? [] as $item) {
            $items[] = [
                'kategori' => 'obat',
                'deskripsi' => Str::limit($item->label(), 250, ''),
                'jumlah' => $item->jumlah,
                'harga' => $item->harga,
            ];
        }

        foreach ($pesanan as $paket) {
            $items[] = [
                'kategori' => 'paket',
                'paket_id' => $paket->paket_id,
                'deskripsi' => "Paket {$paket->nama} ({$paket->no_paket})",
                'jumlah' => 1,
                'harga' => $paket->harga,
            ];
        }

        return array_map(fn ($item) => $item + ['subtotal' => $item['jumlah'] * $item['harga']], $items);
    }

    /** Urutan sesi terakhir yang dipakai tindakan ini, mis. "3" atau "3–4" bila memakai dua sesi sekaligus. */
    private function urutanSesi(KunjunganTindakan $tindakan): string
    {
        $sebelumnya = (int) KunjunganTindakan::where('paket_pasien_item_id', $tindakan->paket_pasien_item_id)
            ->where('id', '<', $tindakan->id)
            ->whereHas('kunjungan', fn ($q) => $q->where('status', '!=', StatusKunjungan::Batal->value))
            ->sum('jumlah');
        $awal = $sebelumnya + 1;
        $akhir = $sebelumnya + $tindakan->jumlah;

        return $awal === $akhir ? (string) $awal : "{$awal}–{$akhir}";
    }

    /**
     * Tagihan berdiri sendiri tanpa kunjungan — penjualan produk OTC, paket, deposit
     * (PRD FR-04, TR-02; temuan teknis 8.3 #3).
     *
     * @param  list<array{kategori: string, deskripsi: string, jumlah: int, harga: int, tindakan_id?: int, paket_id?: int}>  $items
     */
    public function buatMandiri(int $cabangId, ?int $pasienId, array $items, ?string $keterangan = null): Tagihan
    {
        return DB::transaction(function () use ($cabangId, $pasienId, $items, $keterangan) {
            $items = array_map(fn ($item) => $item + ['subtotal' => $item['jumlah'] * $item['harga']], $items);
            $total = array_sum(array_column($items, 'subtotal'));

            $pajakPersen = $this->kasir->pajakPersen();
            $grandTotal = $this->kasir->hitungGrandTotal($total, 0, $pajakPersen);

            $tagihan = Tagihan::create([
                'cabang_id' => $cabangId,
                'no_tagihan' => $this->nomor->noTagihan(now()),
                'pasien_id' => $pasienId,
                'total' => $total,
                'pajak' => $grandTotal - $total,
                'pajak_persen' => $pajakPersen,
                'grand_total' => $grandTotal,
                'status' => StatusTagihan::BelumBayar,
                'keterangan' => $keterangan,
            ]);

            $tagihan->items()->createMany($items);

            // refresh() agar kolom yang tidak diisi (kunjungan_id, dll) ikut ada di respons JSON.
            return $tagihan->refresh();
        });
    }
}
