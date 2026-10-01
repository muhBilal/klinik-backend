<?php

namespace App\Services;

use App\Models\Icd10;
use App\Models\Icd9cm;
use App\Models\Obat;
use Illuminate\Support\Facades\DB;

/**
 * Impor master data dari berkas resmi (PRD v2 AD-10): ICD-10, ICD-9-CM, dan master obat/produk.
 *
 * Format CSV (pemisah `;` atau `,`, baris pertama boleh judul kolom, UTF-8 dengan/tanpa BOM):
 * - icd10  : kode;nama[;sensitif]
 * - icd9cm : kode;nama
 * - obat   : kode;nama;satuan;harga[;jenis;no_bpom;stok_minimum]
 *
 * Idempoten: baris dengan kode yang sudah ada diperbarui, kode baru ditambahkan, kode yang tidak ada di berkas TIDAK dihapus
 * (kode yang sudah dipakai rekam medis tidak boleh hilang). Stok obat tidak pernah diubah lewat impor (pakai penerimaan barang).
 */
class ImporMasterService
{
    public const JENIS = ['icd10', 'icd9cm', 'obat'];

    /** @return array{baru: int, diperbarui: int, sama: int, galat: list<array{baris: int, pesan: string}>} */
    public function impor(string $jenis, string $path): array
    {
        $hasil = ['baru' => 0, 'diperbarui' => 0, 'sama' => 0, 'galat' => []];
        $baris = $this->baca($path);

        DB::transaction(function () use ($jenis, $baris, &$hasil) {
            foreach ($baris as $no => $kolom) {
                try {
                    $status = match ($jenis) {
                        'icd10' => $this->icd10($kolom),
                        'icd9cm' => $this->icd9cm($kolom),
                        'obat' => $this->obat($kolom),
                    };
                    $hasil[$status]++;
                } catch (\InvalidArgumentException $e) {
                    $hasil['galat'][] = ['baris' => $no, 'pesan' => $e->getMessage()];
                }
            }
        });

        $hasil['galat'] = array_slice($hasil['galat'], 0, 50);

        return $hasil;
    }

    /** @return array<int, list<string>> nomor baris (1-based) => kolom */
    private function baca(string $path): array
    {
        $isi = (string) file_get_contents($path);
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);
        $baris = preg_split('/\r\n|\r|\n/', $isi);
        $pemisah = substr_count($baris[0] ?? '', ';') >= substr_count($baris[0] ?? '', ',') ? ';' : ',';

        $hasil = [];
        foreach ($baris as $i => $teks) {
            if (trim($teks) === '') {
                continue;
            }
            $kolom = array_map('trim', str_getcsv($teks, $pemisah, '"', '\\'));
            // Lewati baris judul
            if ($i === 0 && preg_match('/^(kode|code)$/i', $kolom[0] ?? '')) {
                continue;
            }
            $hasil[$i + 1] = $kolom;
        }

        return $hasil;
    }

    private function icd10(array $k): string
    {
        [$kode, $nama] = $this->wajib($k, 2);
        if (! preg_match('/^[A-Z]\d{2}(\.\d{1,2})?$/i', $kode)) {
            throw new \InvalidArgumentException("Kode ICD-10 tidak valid: {$kode}");
        }
        $data = ['nama' => $nama];
        if (isset($k[2]) && $k[2] !== '') {
            $data['sensitif'] = in_array(strtolower($k[2]), ['1', 'ya', 'y', 'true', 'sensitif'], true);
        }

        return $this->simpan(Icd10::class, strtoupper($kode), $data);
    }

    private function icd9cm(array $k): string
    {
        [$kode, $nama] = $this->wajib($k, 2);
        if (! preg_match('/^\d{2}(\.\d{1,2})?$/', $kode)) {
            throw new \InvalidArgumentException("Kode ICD-9-CM tidak valid: {$kode}");
        }

        return $this->simpan(Icd9cm::class, $kode, ['nama' => $nama]);
    }

    private function obat(array $k): string
    {
        [$kode, $nama, $satuan, $harga] = $this->wajib($k, 4);
        $harga = (int) preg_replace('/\D/', '', $harga);
        $jenis = strtolower($k[4] ?? '') ?: 'obat';
        if (! in_array($jenis, Obat::JENIS, true)) {
            throw new \InvalidArgumentException("Jenis produk tidak dikenal: {$jenis}");
        }
        $bpom = strtoupper(str_replace(' ', '', $k[5] ?? '')) ?: null;
        if ($jenis === 'skincare' && ! preg_match('/^N[A-E]\d{11}$/', (string) $bpom)) {
            throw new \InvalidArgumentException("Produk skincare {$kode} wajib nomor notifikasi BPOM yang valid.");
        }

        $data = ['nama' => $nama, 'satuan' => $satuan, 'harga' => $harga, 'jenis' => $jenis, 'no_bpom' => $bpom];
        if (isset($k[6]) && $k[6] !== '') {
            $data['stok_minimum'] = (int) $k[6];
        }

        return $this->simpan(Obat::class, $kode, $data);
    }

    /** @return list<string> */
    private function wajib(array $k, int $n): array
    {
        $isi = array_slice($k, 0, $n);
        if (count($isi) < $n || in_array('', $isi, true)) {
            throw new \InvalidArgumentException("Butuh {$n} kolom pertama terisi.");
        }

        return $isi;
    }

    /** Upsert per model (tercatat audit bila model Auditable). */
    private function simpan(string $model, string $kode, array $data): string
    {
        $baris = $model::query()->when(method_exists($model, 'bootSoftDeletes'), fn ($q) => $q->withTrashed())->firstWhere('kode', $kode);

        if (! $baris) {
            $model::create(['kode' => $kode, ...$data]);

            return 'baru';
        }

        $baris->fill($data);
        if (! $baris->isDirty()) {
            return 'sama';
        }
        $baris->save();

        return 'diperbarui';
    }
}
