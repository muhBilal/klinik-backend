<?php

namespace App\Services;

use App\Enums\JenisCatatanTindakan;
use App\Enums\TipeSumberDaya;
use App\Models\CatatanTindakan;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\StokBatch;
use App\Models\SumberDaya;
use App\Models\Tindakan;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catatan pelaksanaan tindakan (PRD RM-05): area & catatan, parameter alat energy device (ES-02),
 * face chart injeksi dengan produk & batch per titik (ES-01), dan petugas pelaksana (AN-03).
 */
class CatatanTindakanService
{
    /** Relasi yang dikembalikan ke klien setelah simpan / saat dibuka. */
    public const RELASI = [
        'alat:id,kode,nama',
        'pencatat:id,name',
        'titiks.obat:id,kode,nama,satuan',
        'titiks.batch:id,no_batch,kedaluwarsa',
    ];

    /**
     * Pemeriksaan harus masih terbuka dan milik cabang aktif (kunjungan cabang lain → 404 lewat scope cabang).
     */
    public function kunjunganTerbuka(KunjunganTindakan $kunjunganTindakan): Kunjungan
    {
        $kunjungan = Kunjungan::findOrFail($kunjunganTindakan->kunjungan_id);

        if (! $kunjungan->terbuka()) {
            throw ValidationException::withMessages(['status' => 'Pemeriksaan sudah ditutup; catatan tindakan terkunci.']);
        }

        return $kunjungan;
    }

    /**
     * @param  array{jenis?: string, area?: ?string, catatan?: ?string, parameter?: array, sumber_daya_id?: ?int,
     *               petugas_id?: ?int, titiks?: list<array>}  $data
     */
    public function simpan(KunjunganTindakan $kunjunganTindakan, array $data, User $user): CatatanTindakan
    {
        $kunjungan = $this->kunjunganTerbuka($kunjunganTindakan);

        return DB::transaction(function () use ($kunjunganTindakan, $kunjungan, $data, $user) {
            $catatan = $kunjunganTindakan->catatan ?? $kunjunganTindakan->catatan()->make();
            $jenis = isset($data['jenis'])
                ? JenisCatatanTindakan::from($data['jenis'])
                : ($catatan->jenis ?? Tindakan::withTrashed()->whereKey($kunjunganTindakan->tindakan_id)->value('jenis_catatan') ?? JenisCatatanTindakan::Umum);
            $jenis = $jenis instanceof JenisCatatanTindakan ? $jenis : JenisCatatanTindakan::from($jenis);
            $energi = $jenis === JenisCatatanTindakan::Energi;

            if ($energi && ($data['sumber_daya_id'] ?? null)) {
                $this->pastikanAlat((int) $data['sumber_daya_id'], $kunjungan->cabang_id);
            }

            $catatan->fill([
                'jenis' => $jenis,
                'area' => $data['area'] ?? null,
                'catatan' => $data['catatan'] ?? null,
                // Parameter & alat hanya bermakna untuk energy device; jenis lain dikosongkan.
                'parameter' => $energi ? $this->parameter($data['parameter'] ?? []) : null,
                'sumber_daya_id' => $energi ? ($data['sumber_daya_id'] ?? null) : null,
                'dicatat_oleh' => $user->id,
            ])->save();

            if (array_key_exists('titiks', $data) || $jenis !== JenisCatatanTindakan::Injeksi) {
                $this->syncTitik($catatan, $jenis === JenisCatatanTindakan::Injeksi ? ($data['titiks'] ?? []) : [], $kunjungan->cabang_id);
            }

            if (array_key_exists('petugas_id', $data)) {
                if ($data['petugas_id']) {
                    $this->pastikanPetugas([(int) $data['petugas_id']], $kunjungan->cabang_id, 'petugas_id');
                }
                $kunjunganTindakan->update(['petugas_id' => $data['petugas_id']]);
            }

            if (array_key_exists('petugas_tambahan', $data)) {
                $this->syncPetugasTambahan($kunjunganTindakan, $data['petugas_tambahan'] ?? [], $kunjungan->cabang_id);
            }

            return $catatan->load(self::RELASI);
        });
    }

    /**
     * Petugas pelaksana harus petugas medis aktif yang bertugas di cabang kunjungan (atau lintas cabang).
     *
     * @param  array<int|string, int>  $ids  kunci = indeks untuk pesan error
     */
    public function pastikanPetugas(array $ids, int $cabangId, string $field): void
    {
        $valid = User::petugasMedis()
            ->whereIn('id', array_values($ids))
            ->where(fn ($w) => $w->where('cabang_id', $cabangId)->orWhereNull('cabang_id'))
            ->pluck('id')
            ->all();

        foreach ($ids as $indeks => $id) {
            if (! in_array($id, $valid, false)) {
                throw ValidationException::withMessages([
                    str_replace('*', (string) $indeks, $field) => 'Petugas tidak ditemukan atau tidak bertugas di cabang ini.',
                ]);
            }
        }
    }

    /**
     * Petugas tambahan per tindakan (AN-03), replace-all per model agar tercatat di audit.
     *
     * @param  list<array{user_id: int, peran: string}>  $petugas
     */
    private function syncPetugasTambahan(KunjunganTindakan $kunjunganTindakan, array $petugas, int $cabangId): void
    {
        $baru = collect($petugas)->keyBy(fn ($p) => (int) $p['user_id']);

        if ($baru->isNotEmpty()) {
            $this->pastikanPetugas($baru->keys()->values()->all(), $cabangId, 'petugas_tambahan.*.user_id');
        }

        $lama = $kunjunganTindakan->petugasTambahan()->get()->keyBy('user_id');
        $lama->diffKeys($baru)->each->delete();

        foreach ($baru as $userId => $p) {
            ($lama->get($userId) ?? $kunjunganTindakan->petugasTambahan()->make(['user_id' => $userId]))
                ->fill(['peran' => $p['peran']])
                ->save();
        }
    }

    private function pastikanAlat(int $id, int $cabangId): void
    {
        $ada = SumberDaya::withoutGlobalScope('cabang')->whereKey($id)
            ->where('cabang_id', $cabangId)->where('tipe', TipeSumberDaya::Alat)->exists();

        if (! $ada) {
            throw ValidationException::withMessages(['sumber_daya_id' => 'Alat tidak ditemukan di cabang ini.']);
        }
    }

    /** Hanya kunci parameter yang dikenali; nilai kosong dibuang. */
    private function parameter(array $parameter): ?array
    {
        $bersih = array_filter(
            Arr::only($parameter, array_keys(CatatanTindakan::PARAMETER)),
            fn ($v) => $v !== null && $v !== '',
        );

        return $bersih ?: null;
    }

    /** Replace-all titik face chart, per model agar tercatat di audit log. Batch harus milik produk & cabang itu. */
    private function syncTitik(CatatanTindakan $catatan, array $titiks, int $cabangId): void
    {
        foreach ($titiks as $i => $titik) {
            if (($titik['batch_id'] ?? null) === null) {
                continue;
            }

            $batchValid = ($titik['obat_id'] ?? null) && StokBatch::withoutGlobalScope('cabang')
                ->whereKey($titik['batch_id'])
                ->where('obat_id', $titik['obat_id'])
                ->where('cabang_id', $cabangId)
                ->exists();

            if (! $batchValid) {
                throw ValidationException::withMessages(["titiks.{$i}.batch_id" => 'Batch tidak sesuai produk atau bukan stok cabang ini.']);
            }
        }

        $catatan->titiks()->get()->each->delete();

        foreach ($titiks as $titik) {
            $atribut = Arr::only($titik, ['tampilan', 'x', 'y', 'area', 'obat_id', 'batch_id', 'jumlah', 'satuan', 'kedalaman', 'alat', 'catatan']);
            $atribut['tampilan'] ??= 'depan';

            $catatan->titiks()->create($atribut);
        }
    }
}
