<?php

namespace App\Services;

use App\Models\Tindakan;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Simpan treatment beserta harga per cabang, BHP standar (PRD TR-01) & komisi per peran (KM-01).
 */
class TindakanService
{
    /**
     * `hargas` / `bhps` / `komisis` bersifat replace-all bila key ada di data; key tidak dikirim = tidak diubah.
     * Baris diubah per model (bukan hapus-buat ulang massal) sehingga audit log hanya berisi perubahan nyata.
     */
    public function simpan(array $data, ?Tindakan $tindakan = null): Tindakan
    {
        return DB::transaction(function () use ($data, $tindakan) {
            $atribut = Arr::except($data, ['hargas', 'bhps', 'komisis']);
            $tindakan ? $tindakan->update($atribut) : $tindakan = Tindakan::create($atribut);

            if (array_key_exists('hargas', $data)) {
                $this->syncHarga($tindakan, $data['hargas'] ?? []);
            }
            if (array_key_exists('bhps', $data)) {
                $this->syncBhp($tindakan, $data['bhps'] ?? []);
            }
            if (array_key_exists('komisis', $data)) {
                $this->syncKomisi($tindakan, $data['komisis'] ?? []);
            }

            return $tindakan;
        });
    }

    private function syncHarga(Tindakan $tindakan, array $hargas): void
    {
        $lama = $tindakan->hargas()->get()->keyBy('cabang_id');
        $baru = collect($hargas)->keyBy('cabang_id');

        $lama->diffKeys($baru)->each->delete();

        foreach ($baru as $cabangId => $harga) {
            ($lama->get($cabangId) ?? $tindakan->hargas()->make(['cabang_id' => $cabangId]))
                ->fill(['tarif' => $harga['tarif'], 'tersedia' => $harga['tersedia'] ?? true])
                ->save();
        }
    }

    private function syncBhp(Tindakan $tindakan, array $bhps): void
    {
        $lama = $tindakan->bhps()->get()->keyBy('obat_id');
        $baru = collect($bhps)->keyBy('obat_id');

        $lama->diffKeys($baru)->each->delete();

        foreach ($baru as $obatId => $bhp) {
            ($lama->get($obatId) ?? $tindakan->bhps()->make(['obat_id' => $obatId]))
                ->fill(['jumlah' => $bhp['jumlah']])
                ->save();
        }
    }

    private function syncKomisi(Tindakan $tindakan, array $komisis): void
    {
        $lama = $tindakan->komisis()->get()->keyBy(fn ($k) => $k->peran->value);
        $baru = collect($komisis)->keyBy('peran');

        $lama->diffKeys($baru)->each->delete();

        foreach ($baru as $peran => $komisi) {
            ($lama->get($peran) ?? $tindakan->komisis()->make(['peran' => $peran]))
                ->fill(['jenis' => $komisi['jenis'], 'nilai' => $komisi['nilai']])
                ->save();
        }
    }
}
