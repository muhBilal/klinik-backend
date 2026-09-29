<?php

namespace App\Services;

use App\Enums\MetodeBayar;
use App\Enums\StatusKunjungan;
use App\Enums\StatusTagihan;
use App\Models\Kunjungan;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TagihanService
{
    public function __construct(private NomorUrutService $nomor) {}

    /**
     * Susun tagihan dari biaya konsultasi poli, tindakan dan obat pada resep.
     */
    public function buatDariKunjungan(Kunjungan $kunjungan): Tagihan
    {
        $kunjungan->loadMissing(['poli', 'tindakans.tindakan', 'resep.items.obat']);

        $items = [[
            'kategori' => 'konsultasi',
            'deskripsi' => 'Konsultasi '.$kunjungan->poli->nama,
            'jumlah' => 1,
            'harga' => $kunjungan->poli->tarif_konsultasi,
        ]];

        foreach ($kunjungan->tindakans as $tindakan) {
            $items[] = [
                'kategori' => 'tindakan',
                'deskripsi' => $tindakan->tindakan->nama,
                'jumlah' => $tindakan->jumlah,
                'harga' => $tindakan->tarif,
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

        $tagihan = $kunjungan->tagihan()->create([
            'no_tagihan' => $this->nomor->noTagihan(now()),
            'total' => $total,
            'grand_total' => $total,
            'status' => StatusTagihan::BelumBayar,
        ]);

        $tagihan->items()->createMany($items);

        return $tagihan;
    }

    public function bayar(Tagihan $tagihan, MetodeBayar $metode, int $dibayar, int $diskon, User $kasir): Tagihan
    {
        return DB::transaction(function () use ($tagihan, $metode, $dibayar, $diskon, $kasir) {
            $tagihan = Tagihan::whereKey($tagihan->id)->lockForUpdate()->firstOrFail();

            if ($tagihan->status !== StatusTagihan::BelumBayar) {
                throw ValidationException::withMessages(['status' => 'Tagihan ini sudah dibayar atau dibatalkan.']);
            }

            if ($diskon > $tagihan->total) {
                throw ValidationException::withMessages(['diskon' => 'Diskon tidak boleh melebihi total tagihan.']);
            }

            $grandTotal = $tagihan->total - $diskon;

            if ($metode === MetodeBayar::Tunai && $dibayar < $grandTotal) {
                throw ValidationException::withMessages(['dibayar' => 'Nominal pembayaran kurang dari total tagihan.']);
            }

            // Non-tunai dan penjamin dibayar pas sesuai tagihan.
            $dibayar = $metode === MetodeBayar::Tunai ? $dibayar : $grandTotal;

            $tagihan->update([
                'diskon' => $diskon,
                'grand_total' => $grandTotal,
                'metode_bayar' => $metode,
                'dibayar' => $dibayar,
                'kembalian' => $dibayar - $grandTotal,
                'status' => StatusTagihan::Lunas,
                'kasir_id' => $kasir->id,
                'dibayar_at' => now(),
            ]);

            $tagihan->kunjungan()->update(['status' => StatusKunjungan::Selesai]);

            return $tagihan;
        });
    }
}
