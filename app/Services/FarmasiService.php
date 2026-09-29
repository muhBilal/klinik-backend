<?php

namespace App\Services;

use App\Enums\JenisMutasi;
use App\Enums\StatusResep;
use App\Enums\StatusTagihan;
use App\Models\Obat;
use App\Models\Resep;
use App\Models\StokMutasi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FarmasiService
{
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

            $obats = Obat::whereIn('id', $resep->items->pluck('obat_id'))->lockForUpdate()->get()->keyBy('id');

            $kurang = $resep->items
                ->filter(fn ($item) => $obats[$item->obat_id]->stok < $item->jumlah)
                ->map(fn ($item) => "{$obats[$item->obat_id]->nama} (stok {$obats[$item->obat_id]->stok}, diminta {$item->jumlah})");

            if ($kurang->isNotEmpty()) {
                throw ValidationException::withMessages(['stok' => 'Stok tidak mencukupi: '.$kurang->implode(', ')]);
            }

            foreach ($resep->items as $item) {
                $this->catatMutasi($obats[$item->obat_id], JenisMutasi::Keluar, -$item->jumlah, $apoteker, $resep->no_resep, 'Penyerahan resep');
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
     * Mutasi stok manual: masuk (penerimaan), keluar (rusak/kadaluarsa), penyesuaian (stok opname -> nilai absolut).
     */
    public function mutasiManual(Obat $obat, JenisMutasi $jenis, int $jumlah, ?string $keterangan, User $user): StokMutasi
    {
        return DB::transaction(function () use ($obat, $jenis, $jumlah, $keterangan, $user) {
            $obat = Obat::whereKey($obat->id)->lockForUpdate()->firstOrFail();

            $delta = match ($jenis) {
                JenisMutasi::Masuk => $jumlah,
                JenisMutasi::Keluar => -$jumlah,
                JenisMutasi::Penyesuaian => $jumlah - $obat->stok,
            };

            if ($obat->stok + $delta < 0) {
                throw ValidationException::withMessages(['jumlah' => "Stok {$obat->nama} tidak mencukupi (tersisa {$obat->stok})."]);
            }

            return $this->catatMutasi($obat, $jenis, $delta, $user, null, $keterangan);
        });
    }

    private function catatMutasi(Obat $obat, JenisMutasi $jenis, int $delta, User $user, ?string $referensi, ?string $keterangan): StokMutasi
    {
        $obat->stok += $delta;
        $obat->save();

        return $obat->mutasis()->create([
            'jenis' => $jenis,
            'jumlah' => $delta,
            'stok_akhir' => $obat->stok,
            'referensi' => $referensi,
            'keterangan' => $keterangan,
            'user_id' => $user->id,
        ]);
    }
}
