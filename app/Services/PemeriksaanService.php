<?php

namespace App\Services;

use App\Enums\Izin;
use App\Enums\StatusKunjungan;
use App\Enums\StatusResep;
use App\Models\Kunjungan;
use App\Models\Obat;
use App\Models\Pemeriksaan;
use App\Models\Tindakan;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PemeriksaanService
{
    public function __construct(
        private NomorUrutService $nomor,
        private TagihanService $tagihan,
    ) {}

    /**
     * Pasien dipanggil ke ruang periksa.
     */
    public function panggil(Kunjungan $kunjungan, User $user): Kunjungan
    {
        if ($kunjungan->status !== StatusKunjungan::Menunggu) {
            throw ValidationException::withMessages(['status' => 'Hanya kunjungan berstatus menunggu yang dapat dipanggil.']);
        }

        $kunjungan->update([
            'status' => StatusKunjungan::Diperiksa,
            'dipanggil_at' => now(),
            'dokter_id' => $kunjungan->dokter_id ?? ($user->tercatatSebagaiDokter() ? $user->id : null),
        ]);

        return $kunjungan;
    }

    /**
     * Simpan (upsert) data pemeriksaan. Tanpa izin pemeriksaan.dokter (perawat, terapis) hanya tanda vital &
     * anamnesis (subjektif) yang disimpan; pemegang izin pemeriksaan.dokter mengisi seluruh SOAP, diagnosa,
     * tindakan dan resep.
     */
    public function simpan(Kunjungan $kunjungan, array $data, User $user): Kunjungan
    {
        if (! in_array($kunjungan->status, [StatusKunjungan::Menunggu, StatusKunjungan::Diperiksa], true)) {
            throw ValidationException::withMessages(['status' => 'Pemeriksaan sudah ditutup dan tidak dapat diubah.']);
        }

        $isPerawat = ! $user->punyaIzin(Izin::PemeriksaanDokter);

        return DB::transaction(function () use ($kunjungan, $data, $user, $isPerawat) {
            $fields = $isPerawat
                ? [...Pemeriksaan::VITAL_FIELDS, 'subjektif']
                : [...Pemeriksaan::VITAL_FIELDS, ...Pemeriksaan::SOAP_FIELDS];

            $attributes = Arr::only($data, $fields);
            $attributes[$isPerawat ? 'perawat_id' : 'dokter_id'] = $user->id;

            $pemeriksaan = $kunjungan->pemeriksaan()->updateOrCreate(['kunjungan_id' => $kunjungan->id], $attributes);

            if (! $isPerawat) {
                if (array_key_exists('diagnosas', $data)) {
                    $this->syncDiagnosa($pemeriksaan, $data['diagnosas']);
                }
                if (array_key_exists('tindakans', $data)) {
                    $this->syncTindakan($kunjungan, $data['tindakans']);
                }
                if (array_key_exists('resep', $data)) {
                    $this->syncResep($kunjungan, $data['resep'] ?? [], $data['catatan_resep'] ?? null, $user);
                }
            }

            return $kunjungan->loadDetail();
        });
    }

    /**
     * Dokter menutup pemeriksaan; tagihan dibuat dan pasien diarahkan ke kasir.
     */
    public function selesai(Kunjungan $kunjungan, User $user): Kunjungan
    {
        if ($kunjungan->status !== StatusKunjungan::Diperiksa) {
            throw ValidationException::withMessages(['status' => 'Pasien belum dipanggil atau pemeriksaan sudah selesai.']);
        }

        $pemeriksaan = $kunjungan->pemeriksaan;

        if (! $pemeriksaan || $pemeriksaan->diagnosas()->doesntExist()) {
            throw ValidationException::withMessages(['diagnosas' => 'Minimal satu diagnosa (ICD-10) harus diisi sebelum menyelesaikan pemeriksaan.']);
        }

        return DB::transaction(function () use ($kunjungan, $user) {
            $kunjungan->update([
                'status' => StatusKunjungan::MenungguPembayaran,
                'selesai_at' => now(),
                'dokter_id' => $kunjungan->dokter_id ?? $user->id,
            ]);

            $this->tagihan->buatDariKunjungan($kunjungan);

            return $kunjungan->loadDetail();
        });
    }

    private function syncDiagnosa(Pemeriksaan $pemeriksaan, array $diagnosas): void
    {
        // Hapus per model (bukan query massal) agar setiap perubahan rekam medis tercatat di audit log.
        $pemeriksaan->diagnosas()->get()->each->delete();

        foreach ($diagnosas as $index => $diagnosa) {
            $pemeriksaan->diagnosas()->create([
                'icd10_id' => $diagnosa['icd10_id'],
                'jenis' => $diagnosa['jenis'] ?? ($index === 0 ? 'primer' : 'sekunder'),
            ]);
        }
    }

    private function syncTindakan(Kunjungan $kunjungan, array $tindakans): void
    {
        $kunjungan->tindakans()->get()->each->delete();

        $master = Tindakan::whereIn('id', Arr::pluck($tindakans, 'tindakan_id'))->get()->keyBy('id');

        foreach ($tindakans as $item) {
            $kunjungan->tindakans()->create([
                'tindakan_id' => $item['tindakan_id'],
                'jumlah' => $item['jumlah'] ?? 1,
                'tarif' => $master[$item['tindakan_id']]->tarif,
                'keterangan' => $item['keterangan'] ?? null,
            ]);
        }
    }

    private function syncResep(Kunjungan $kunjungan, array $items, ?string $catatan, User $user): void
    {
        $resep = $kunjungan->resep;

        if ($resep && $resep->status !== StatusResep::Menunggu) {
            throw ValidationException::withMessages(['resep' => 'Resep sudah diproses farmasi dan tidak dapat diubah.']);
        }

        if (empty($items)) {
            $resep?->delete();

            return;
        }

        $resep ??= $kunjungan->resep()->create([
            'cabang_id' => $kunjungan->cabang_id,
            'no_resep' => $this->nomor->noResep(now()),
            'status' => StatusResep::Menunggu,
        ]);

        $resep->update(['dokter_id' => $user->id, 'catatan' => $catatan]);
        $resep->items()->get()->each->delete();

        $obats = Obat::whereIn('id', Arr::pluck($items, 'obat_id'))->get()->keyBy('id');

        foreach ($items as $item) {
            $resep->items()->create([
                'obat_id' => $item['obat_id'],
                'jumlah' => $item['jumlah'],
                'aturan_pakai' => $item['aturan_pakai'],
                'harga' => $obats[$item['obat_id']]->harga,
            ]);
        }
    }
}
