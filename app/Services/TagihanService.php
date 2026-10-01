<?php

namespace App\Services;

use App\Enums\StatusKunjungan;
use App\Enums\StatusTagihan;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\Tagihan;
use App\Support\Gigi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TagihanService
{
    public function __construct(
        private NomorUrutService $nomor,
        private KasirService $kasir,
    ) {}

    /**
     * Susun tagihan dari biaya konsultasi poli, tindakan dan obat pada resep (BL-01). Tindakan yang memakai sesi paket
     * (TR-02) ditagih Rp 0 dengan keterangan nomor paket & urutan sesi.
     */
    public function buatDariKunjungan(Kunjungan $kunjungan): Tagihan
    {
        $kunjungan->loadMissing(['poli', 'tindakans.tindakan', 'tindakans.paketItem.paketPasien:id,no_paket', 'resep.items.obat']);

        $items = [[
            'kategori' => 'konsultasi',
            'deskripsi' => 'Konsultasi '.$kunjungan->poli->nama,
            'jumlah' => 1,
            'harga' => $kunjungan->poli->tarif_konsultasi,
        ]];

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
                'deskripsi' => "{$item->obat->nama} ({$item->obat->satuan})",
                'jumlah' => $item->jumlah,
                'harga' => $item->harga,
            ];
        }

        $items = array_map(fn ($item) => $item + ['subtotal' => $item['jumlah'] * $item['harga']], $items);
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

        return $tagihan;
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
