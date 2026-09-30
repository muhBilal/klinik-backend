<?php

namespace App\Services;

use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\KunjunganTindakanBhp;
use App\Models\Obat;
use App\Models\Tindakan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pemakaian bahan habis pakai per tindakan (PRD IN-02).
 *
 * Alurnya dua tahap: saat tindakan dicatat, baris BHP dibuat dari standar katalog sebagai draft;
 * petugas boleh mengoreksi jumlah aktual; stok baru dipotong saat pemeriksaan diselesaikan.
 * Dengan begitu koreksi pemakaian tidak perlu membatalkan mutasi stok.
 */
class BhpService
{
    public function __construct(
        private InventoriService $inventori,
        private PengaturanService $pengaturan,
    ) {}

    /**
     * Buat baris BHP draft dari BHP standar treatment. Dipanggil setelah tindakan kunjungan disimpan.
     * Baris yang stoknya sudah dipotong tidak disentuh.
     */
    public function siapkanDariStandar(KunjunganTindakan $kunjunganTindakan): void
    {
        $standar = Tindakan::withTrashed()
            ->whereKey($kunjunganTindakan->tindakan_id)
            ->firstOrFail()
            ->bhps()->get();

        foreach ($standar as $bhp) {
            // Jumlah standar dikali banyaknya tindakan pada kunjungan ini.
            $jumlah = round($bhp->jumlah * $kunjunganTindakan->jumlah, 3);

            $kunjunganTindakan->bhps()->create([
                'obat_id' => $bhp->obat_id,
                'jumlah_standar' => $jumlah,
                'jumlah' => $jumlah,
            ]);
        }
    }

    /**
     * Koreksi pemakaian aktual oleh petugas (IN-02). Hanya boleh sebelum stok dipotong.
     *
     * @param  list<array{obat_id: int, jumlah: float, batch_id?: int}>  $items  replace-all
     */
    public function koreksi(KunjunganTindakan $kunjunganTindakan, array $items, User $user): KunjunganTindakan
    {
        return DB::transaction(function () use ($kunjunganTindakan, $items, $user) {
            if ($kunjunganTindakan->bhps()->where('stok_dipotong', true)->exists()) {
                throw ValidationException::withMessages([
                    'bhps' => 'Pemakaian BHP sudah dipotong dari stok dan tidak bisa diubah di sini.',
                ]);
            }

            $obats = Obat::withTrashed()->whereIn('id', array_column($items, 'obat_id'))->get()->keyBy('id');
            $lama = $kunjunganTindakan->bhps()->get()->keyBy('obat_id');
            $baru = collect($items)->keyBy('obat_id');

            $lama->diffKeys($baru)->each->delete();

            foreach ($baru as $obatId => $item) {
                $obat = $obats[$obatId] ?? throw ValidationException::withMessages(['bhps' => 'Obat tidak ditemukan.']);

                if (! $obat->fraksional && abs($item['jumlah'] - round($item['jumlah'])) > 0.0005) {
                    throw ValidationException::withMessages([
                        'bhps' => "{$obat->nama} tidak bisa dipakai sebagian. Isi jumlah dalam bilangan bulat {$obat->satuan}.",
                    ]);
                }

                ($lama->get($obatId) ?? $kunjunganTindakan->bhps()->make([
                    'obat_id' => $obatId,
                    'jumlah_standar' => 0,
                ]))->fill([
                    'jumlah' => $item['jumlah'],
                    'batch_id' => $item['batch_id'] ?? null,
                    'dicatat_oleh' => $user->id,
                ])->save();
            }

            return $kunjunganTindakan->load('bhps.obat:id,kode,nama,satuan,fraksional');
        });
    }

    /**
     * Potong stok untuk seluruh BHP kunjungan yang belum dipotong (IN-02).
     * Dipanggil saat pemeriksaan diselesaikan; idempoten karena baris ditandai `stok_dipotong`.
     *
     * Bila stok cabang kurang, perilaku mengikuti pengaturan `inventori.blokir_bhp_stok_kurang`:
     * default-nya pemeriksaan tetap bisa ditutup dan baris ditinggal `stok_dipotong = false`
     * (selisih diselesaikan lewat stok opname) — rekam medis & tagihan tidak boleh tersandera data stok.
     *
     * @return list<string> peringatan stok kurang, untuk ditampilkan ke petugas
     */
    public function potongStok(Kunjungan $kunjungan, User $user): array
    {
        return DB::transaction(function () use ($kunjungan, $user) {
            $barisBhp = KunjunganTindakanBhp::query()
                ->whereIn('kunjungan_tindakan_id', $kunjungan->tindakans()->pluck('id'))
                ->where('stok_dipotong', false)
                ->where('jumlah', '>', 0)
                ->with('obat')
                ->get();

            $blokir = (bool) $this->pengaturan->get('inventori.blokir_bhp_stok_kurang');
            $peringatan = [];

            foreach ($barisBhp as $baris) {
                try {
                    $terpakai = $this->inventori->keluarkan(
                        $baris->obat,
                        $kunjungan->cabang_id,
                        $baris->jumlah,
                        $user,
                        $kunjungan->no_registrasi,
                        'Pemakaian BHP tindakan',
                        $baris->batch_id,
                    );
                } catch (ValidationException $e) {
                    if ($blokir) {
                        throw $e;
                    }

                    $peringatan[] = $e->validator->errors()->first();

                    continue;
                }

                $baris->update([
                    // Bila terbagi ke beberapa batch, batch pertama yang dicatat sebagai rujukan.
                    'batch_id' => $baris->batch_id ?? ($terpakai[0]['batch_id'] ?? null),
                    'stok_dipotong' => true,
                ]);
            }

            return $peringatan;
        });
    }
}
