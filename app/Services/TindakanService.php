<?php

namespace App\Services;

use App\Models\SumberDaya;
use App\Models\Tindakan;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Simpan treatment beserta harga per cabang, BHP standar (PRD TR-01) & ruang/alat wajib (BK-08).
 */
class TindakanService
{
    /**
     * `hargas` / `bhps` bersifat replace-all bila key ada di data; key tidak dikirim = tidak diubah.
     * Baris diubah per model (bukan hapus-buat ulang massal) sehingga audit log hanya berisi perubahan nyata.
     */
    public function simpan(array $data, ?Tindakan $tindakan = null): Tindakan
    {
        return DB::transaction(function () use ($data, $tindakan) {
            $atribut = Arr::except($data, ['hargas', 'bhps', 'sumber_daya_ids']);
            $tindakan ? $tindakan->update($atribut) : $tindakan = Tindakan::create($atribut);

            if (array_key_exists('hargas', $data)) {
                $this->syncHarga($tindakan, $data['hargas'] ?? []);
            }
            if (array_key_exists('bhps', $data)) {
                $this->syncBhp($tindakan, $data['bhps'] ?? []);
            }
            if (array_key_exists('sumber_daya_ids', $data)) {
                $this->syncSumberDaya($tindakan, $data['sumber_daya_ids'] ?? []);
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

    /**
     * Ruang/alat wajib (BK-08). Ruang/alat milik cabang lain yang tidak terlihat pengguna (staf terikat cabang)
     * dipertahankan, sehingga manajer cabang tidak menghapus pengaturan cabang lain.
     */
    private function syncSumberDaya(Tindakan $tindakan, array $ids): void
    {
        $terlihat = SumberDaya::query()->pluck('id');
        $ids = collect($ids)->map(fn ($id) => (int) $id);

        if ($ids->diff($terlihat)->isNotEmpty()) {
            throw ValidationException::withMessages(['sumber_daya_ids' => 'Ruang/alat bukan milik cabang aktif.']);
        }

        $lama = $tindakan->sumberDayas()->pluck('sumber_dayas.id');
        $tetap = $lama->diff($terlihat);
        $baru = $tetap->merge($ids)->unique()->values();

        $perubahan = $tindakan->sumberDayas()->sync($baru->all());

        if ($perubahan['attached'] || $perubahan['detached']) {
            app(AuditService::class)->catat('ubah', 'tindakan', $tindakan->id, [
                'label' => $tindakan->auditLabel(),
                'perubahan' => ['sumber_daya_ids' => ['lama' => $lama->sort()->values()->all(), 'baru' => $baru->sort()->values()->all()]],
            ]);
        }
    }
}
