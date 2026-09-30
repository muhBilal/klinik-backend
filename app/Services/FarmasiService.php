<?php

namespace App\Services;

use App\Enums\JenisMutasi;
use App\Enums\StatusResep;
use App\Enums\StatusTagihan;
use App\Models\Obat;
use App\Models\Resep;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\CabangAktif;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FarmasiService
{
    public function __construct(private InventoriService $inventori) {}

    /**
     * Batalkan resep yang belum diserahkan (temuan teknis 8.3 #7).
     * Stok tidak tersentuh karena baru berkurang saat penyerahan.
     */
    public function batal(Resep $resep, string $alasan, User $user): Resep
    {
        return DB::transaction(function () use ($resep, $alasan, $user) {
            $resep = Resep::whereKey($resep->id)->lockForUpdate()->firstOrFail();

            if ($resep->status !== StatusResep::Menunggu) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya resep yang belum diserahkan yang bisa dibatalkan.',
                ]);
            }

            $resep->update([
                'status' => StatusResep::Batal,
                'dibatalkan_at' => now(),
                'dibatalkan_oleh' => $user->id,
                'alasan_batal' => $alasan,
            ]);

            return $resep;
        });
    }

    /**
     * Serahkan obat ke pasien: stok dikurangi dan dicatat di kartu stok.
     * Alur klinik: poli -> kasir (lunas) -> farmasi.
     */
    public function serahkan(Resep $resep, User $apoteker): Resep
    {
        return DB::transaction(function () use ($resep, $apoteker) {
            $resep = Resep::whereKey($resep->id)->lockForUpdate()
                ->with(['items:id,resep_id,obat_id,jumlah', 'kunjungan:id', 'kunjungan.tagihan:id,kunjungan_id,status'])
                ->firstOrFail();

            if ($resep->status !== StatusResep::Menunggu) {
                throw ValidationException::withMessages(['status' => 'Resep sudah diserahkan atau dibatalkan.']);
            }

            if ($resep->kunjungan->tagihan?->status !== StatusTagihan::Lunas) {
                throw ValidationException::withMessages(['tagihan' => 'Tagihan pasien belum lunas. Arahkan pasien ke kasir terlebih dahulu.']);
            }

            $obats = Obat::withTrashed()->whereIn('id', $resep->items->pluck('obat_id'))->get()->keyBy('id');

            // Stok diambil per cabang resep dengan FEFO (IN-01); kekurangan dilaporkan sekaligus.
            $kurang = $resep->items
                ->filter(fn ($item) => $obats[$item->obat_id]->stokDi($resep->cabang_id) + 0.0005 < $item->jumlah)
                ->map(function ($item) use ($obats, $resep) {
                    $obat = $obats[$item->obat_id];

                    return "{$obat->nama} (stok {$obat->stokDi($resep->cabang_id)}, diminta {$item->jumlah})";
                });

            if ($kurang->isNotEmpty()) {
                throw ValidationException::withMessages(['stok' => 'Stok tidak mencukupi: '.$kurang->implode(', ')]);
            }

            foreach ($resep->items as $item) {
                $this->inventori->keluarkan(
                    $obats[$item->obat_id], $resep->cabang_id, $item->jumlah, $apoteker,
                    $resep->no_resep, 'Penyerahan resep',
                );
            }

            $resep->update([
                'status' => StatusResep::Diserahkan,
                'apoteker_id' => $apoteker->id,
                'diserahkan_at' => now(),
            ]);

            return $resep;
        });
    }

    /**
     * Mutasi stok manual pada satu cabang. Stok nyata disimpan per batch (IN-01), jadi mutasi tanpa
     * nomor batch masuk/keluar lewat batch "tanpa nomor" cabang tersebut:
     * - masuk        -> penerimaan tanpa data batch
     * - keluar       -> rusak / hilang, diambil FEFO
     * - penyesuaian  -> hasil stok opname; `$jumlah` adalah stok akhir yang diinginkan di cabang itu
     */
    public function mutasiManual(Obat $obat, JenisMutasi $jenis, float $jumlah, ?string $keterangan, User $user, ?int $cabangId = null): StokMutasi
    {
        $cabangId ??= app(CabangAktif::class)->untukDataBaru();

        return DB::transaction(function () use ($obat, $jenis, $jumlah, $keterangan, $user, $cabangId) {
            if ($jenis === JenisMutasi::Masuk) {
                $this->inventori->terima($obat, $cabangId, $jumlah, null, null, $user, $keterangan);

                return $this->mutasiTerakhir($obat, $cabangId);
            }

            if ($jenis === JenisMutasi::Keluar) {
                $this->inventori->keluarkan($obat, $cabangId, $jumlah, $user, null, $keterangan, null, 'jumlah');

                return $this->mutasiTerakhir($obat, $cabangId);
            }

            // Penyesuaian: selisih terhadap stok cabang saat ini, diterapkan ke batch tanpa nomor.
            $selisih = round($jumlah - $obat->stokDi($cabangId), 3);

            if ($selisih > 0) {
                $this->inventori->terima($obat, $cabangId, $selisih, null, null, $user, $keterangan);
            } elseif ($selisih < 0) {
                $this->inventori->keluarkan($obat, $cabangId, abs($selisih), $user, null, $keterangan, null, 'jumlah');
            } else {
                throw ValidationException::withMessages(['jumlah' => 'Stok sudah sesuai, tidak ada yang disesuaikan.']);
            }

            $mutasi = $this->mutasiTerakhir($obat, $cabangId);
            $mutasi->update(['jenis' => JenisMutasi::Penyesuaian]);

            return $mutasi;
        });
    }

    private function mutasiTerakhir(Obat $obat, int $cabangId): StokMutasi
    {
        return StokMutasi::where('obat_id', $obat->id)->where('cabang_id', $cabangId)->latest('id')->firstOrFail();
    }
}
