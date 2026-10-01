<?php

namespace App\Services;

use App\Enums\KategoriAlergi;
use App\Enums\StatusKehamilan;
use App\Models\Pasien;
use App\Models\PasienAlergi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Data klinis pasien terstruktur (PRD PS-03): profil (Fitzpatrick, hamil/menyusui, riwayat obat & penyakit) dan daftar alergi.
 * Ditampilkan sebagai peringatan di pemeriksaan, farmasi & detail pasien; hanya untuk pemegang `rme.lihat`.
 */
class DataKlinisService
{
    private const PROFIL = ['fitzpatrick', 'status_kehamilan', 'riwayat_obat', 'riwayat_penyakit'];

    /** Bentuk respons API: `{ klinis: {...}|null, alergis: [...] }`. */
    public function data(Pasien $pasien): array
    {
        return [
            'klinis' => $pasien->klinis()->with('pembaru:id,name')->first(),
            'alergis' => $pasien->alergis()->with('obat:id,kode,nama')->orderBy('id')->get(),
        ];
    }

    /**
     * Kunci profil yang dikirim saja yang diubah; `alergis` replace-all bila dikirim (baris lama dicocokkan lewat `id`).
     * Tanggal status kehamilan diperbarui saat statusnya berubah atau dikonfirmasi ulang (`konfirmasi_kehamilan`).
     */
    public function simpan(Pasien $pasien, array $data, User $user): void
    {
        if (in_array($data['status_kehamilan'] ?? null, [StatusKehamilan::Hamil->value, StatusKehamilan::Menyusui->value], true)
            && $pasien->jenis_kelamin !== 'P') {
            throw ValidationException::withMessages(['status_kehamilan' => 'Status hamil/menyusui hanya untuk pasien perempuan.']);
        }

        DB::transaction(function () use ($pasien, $data, $user) {
            $profil = array_intersect_key($data, array_flip(self::PROFIL));

            if ($profil || ! empty($data['konfirmasi_kehamilan'])) {
                $klinis = $pasien->klinis()->firstOrNew();
                $hamilLama = $klinis->status_kehamilan?->value;
                $klinis->fill($profil);

                $hamilBaru = array_key_exists('status_kehamilan', $profil) ? $profil['status_kehamilan'] : $hamilLama;
                if ($hamilBaru !== $hamilLama || (! empty($data['konfirmasi_kehamilan']) && $hamilBaru)) {
                    $klinis->status_kehamilan_at = $hamilBaru ? today() : null;
                }

                if (! $klinis->exists || $klinis->isDirty()) {
                    $klinis->diperbarui_oleh = $user->id;
                    $klinis->save();
                }
            }

            if (array_key_exists('alergis', $data)) {
                $this->syncAlergi($pasien, $data['alergis'] ?? [], $user);
            }
        });
    }

    /** Diubah per baris (bukan hapus-buat ulang) agar audit log hanya berisi perubahan nyata. */
    private function syncAlergi(Pasien $pasien, array $alergis, User $user): void
    {
        $lama = $pasien->alergis()->get()->keyBy('id');
        $zat = [];

        foreach ($alergis as $i => $alergi) {
            $kunci = mb_strtolower(trim($alergi['zat']));
            if (isset($zat[$kunci])) {
                throw ValidationException::withMessages(["alergis.{$i}.zat" => 'Alergi ini sudah ada di daftar.']);
            }
            $zat[$kunci] = true;

            if (! empty($alergi['id']) && ! $lama->has($alergi['id'])) {
                throw ValidationException::withMessages(["alergis.{$i}.id" => 'Data alergi tidak ditemukan pada pasien ini.']);
            }
        }

        $dipakai = collect($alergis)->pluck('id')->filter()->all();
        $lama->except($dipakai)->each->delete();

        foreach ($alergis as $alergi) {
            $baris = ! empty($alergi['id'])
                ? $lama->get($alergi['id'])
                : new PasienAlergi(['pasien_id' => $pasien->id, 'dicatat_oleh' => $user->id]);

            $baris->fill([
                'kategori' => $alergi['kategori'],
                'zat' => trim($alergi['zat']),
                // Tautan obat hanya bermakna untuk alergi obat
                'obat_id' => $alergi['kategori'] === KategoriAlergi::Obat->value ? ($alergi['obat_id'] ?? null) : null,
                'reaksi' => $alergi['reaksi'] ?? null,
                'keparahan' => $alergi['keparahan'] ?? null,
            ])->save();
        }
    }
}
