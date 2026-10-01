<?php

namespace App\Services;

use App\Enums\JenisMutasi;
use App\Models\Cabang;
use App\Models\Obat;
use App\Models\StokBatch;
use App\Models\StokMutasi;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stok per cabang, per batch, dengan kedaluwarsa (PRD IN-01, IN-03).
 *
 * Aturan: setiap perubahan stok lewat service ini agar `stok_batches`, total `obats.stok`, dan kartu
 * stok (`stok_mutasis`) tetap konsisten. Pengeluaran memakai FEFO — batch yang paling cepat
 * kedaluwarsa dipakai lebih dulu.
 */
class InventoriService
{
    /** Toleransi pembandingan desimal 3 digit. */
    private const EPSILON = 0.0005;

    /**
     * Penerimaan barang ke satu batch. Batch dengan nomor & kedaluwarsa sama akan ditambah, bukan diduplikasi.
     */
    public function terima(
        Obat $obat,
        int $cabangId,
        float $jumlah,
        ?string $noBatch,
        ?CarbonInterface $kedaluwarsa,
        User $user,
        ?string $keterangan = null,
    ): StokBatch {
        if ($jumlah <= 0) {
            throw ValidationException::withMessages(['jumlah' => 'Jumlah penerimaan harus lebih dari nol.']);
        }

        $this->pastikanJumlahSah($obat, $jumlah);

        return DB::transaction(function () use ($obat, $cabangId, $jumlah, $noBatch, $kedaluwarsa, $user, $keterangan) {
            if ($kedaluwarsa && $kedaluwarsa->isPast()) {
                throw ValidationException::withMessages(['kedaluwarsa' => 'Tanggal kedaluwarsa sudah lewat.']);
            }

            $batch = StokBatch::withoutGlobalScope('cabang')
                ->where('obat_id', $obat->id)
                ->where('cabang_id', $cabangId)
                ->where('no_batch', $noBatch)
                ->whereDate('kedaluwarsa', $kedaluwarsa)
                ->lockForUpdate()
                ->first();

            if ($batch) {
                $batch->jumlah += $jumlah;
                $batch->jumlah_awal += $jumlah;
                $batch->save();
            } else {
                $batch = StokBatch::create([
                    'obat_id' => $obat->id,
                    'cabang_id' => $cabangId,
                    'no_batch' => $noBatch,
                    'kedaluwarsa' => $kedaluwarsa,
                    'jumlah' => $jumlah,
                    'jumlah_awal' => $jumlah,
                ]);
            }

            $this->catat($obat, $cabangId, $batch, JenisMutasi::Masuk, $jumlah, $user, null, $keterangan);

            return $batch;
        });
    }

    /**
     * Keluarkan stok memakai FEFO; boleh terbagi ke beberapa batch.
     *
     * @param  string  $kunciStok  field tujuan pesan "stok tidak cukup"; mutasi manual memakai `jumlah`
     * @return list<array{batch_id: int, jumlah: float}> batch yang terpakai, untuk dicatat pemanggil
     */
    public function keluarkan(
        Obat $obat,
        int $cabangId,
        float $jumlah,
        User $user,
        ?string $referensi = null,
        ?string $keterangan = null,
        ?int $batchId = null,
        string $kunciStok = 'stok',
    ): array {
        if ($jumlah <= 0) {
            throw ValidationException::withMessages(['jumlah' => 'Jumlah pengeluaran harus lebih dari nol.']);
        }

        $this->pastikanJumlahSah($obat, $jumlah);

        return DB::transaction(function () use ($obat, $cabangId, $jumlah, $user, $referensi, $keterangan, $batchId, $kunciStok) {
            $batches = StokBatch::withoutGlobalScope('cabang')
                ->where('obat_id', $obat->id)
                ->where('cabang_id', $cabangId)
                ->when($batchId, fn ($q) => $q->whereKey($batchId))
                ->tersedia()
                ->urutFefo()
                ->lockForUpdate()
                ->get();

            $tersedia = (float) $batches->sum('jumlah');

            if ($tersedia + self::EPSILON < $jumlah) {
                throw ValidationException::withMessages([
                    $kunciStok => "Stok {$obat->nama} di cabang ini tidak mencukupi (tersisa {$this->angka($tersedia)} {$obat->satuan}, diminta {$this->angka($jumlah)}).",
                ]);
            }

            $sisa = $jumlah;
            $terpakai = [];

            foreach ($batches as $batch) {
                if ($sisa <= self::EPSILON) {
                    break;
                }

                $ambil = min($batch->jumlah, $sisa);

                $batch->jumlah = round($batch->jumlah - $ambil, 3);
                // Vial fraksional yang baru disentuh: mulai hitung masa pakai setelah dibuka (IN-03).
                if ($obat->fraksional && $batch->dibuka_at === null) {
                    $batch->dibuka_at = now();
                    $batch->kedaluwarsa_dibuka_at = $obat->jam_pakai_setelah_buka
                        ? now()->addHours($obat->jam_pakai_setelah_buka)
                        : null;
                }
                $batch->save();

                $this->catat($obat, $cabangId, $batch, JenisMutasi::Keluar, -$ambil, $user, $referensi, $keterangan);

                $terpakai[] = ['batch_id' => $batch->id, 'jumlah' => round($ambil, 3)];
                $sisa = round($sisa - $ambil, 3);
            }

            return $terpakai;
        });
    }

    /**
     * Stok opname satu batch: set jumlah ke nilai hasil hitung fisik (IN-05 sebagian).
     */
    public function sesuaikan(StokBatch $batch, float $jumlahBaru, User $user, ?string $keterangan = null): StokBatch
    {
        if ($jumlahBaru < 0) {
            throw ValidationException::withMessages(['jumlah' => 'Jumlah tidak boleh negatif.']);
        }

        return DB::transaction(function () use ($batch, $jumlahBaru, $user, $keterangan) {
            $batch = StokBatch::withoutGlobalScope('cabang')->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $obat = Obat::withTrashed()->whereKey($batch->obat_id)->firstOrFail();

            $this->pastikanJumlahSah($obat, $jumlahBaru);

            $delta = round($jumlahBaru - $batch->jumlah, 3);

            if (abs($delta) < self::EPSILON) {
                return $batch;
            }

            $batch->jumlah = $jumlahBaru;
            $batch->save();

            $this->catat($obat, $batch->cabang_id, $batch, JenisMutasi::Penyesuaian, $delta, $user, null, $keterangan);

            return $batch;
        });
    }

    /**
     * Mutasi antar cabang (IN-05): pindahkan sebagian isi satu batch ke cabang lain dengan nomor batch & kedaluwarsa
     * yang sama. Tercatat dua kali di kartu stok (keluar di cabang asal, masuk di cabang tujuan) dengan referensi yang sama.
     */
    public function pindahCabang(StokBatch $batch, int $cabangTujuanId, float $jumlah, User $user, ?string $keterangan = null): StokBatch
    {
        if ($jumlah <= 0) {
            throw ValidationException::withMessages(['jumlah' => 'Jumlah mutasi harus lebih dari nol.']);
        }

        return DB::transaction(function () use ($batch, $cabangTujuanId, $jumlah, $user, $keterangan) {
            $batch = StokBatch::withoutGlobalScope('cabang')->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $obat = Obat::withTrashed()->whereKey($batch->obat_id)->firstOrFail();

            if ($batch->cabang_id === $cabangTujuanId) {
                throw ValidationException::withMessages(['cabang_tujuan_id' => 'Cabang tujuan sama dengan cabang asal.']);
            }

            $tujuan = Cabang::whereKey($cabangTujuanId)->where('is_active', true)->first();

            if (! $tujuan) {
                throw ValidationException::withMessages(['cabang_tujuan_id' => 'Cabang tujuan tidak aktif.']);
            }

            $this->pastikanJumlahSah($obat, $jumlah);

            if ($batch->jumlah + self::EPSILON < $jumlah) {
                throw ValidationException::withMessages([
                    'jumlah' => "Isi batch tinggal {$this->angka($batch->jumlah)} {$obat->satuan}.",
                ]);
            }

            if ($batch->kedaluwarsa?->isPast()) {
                throw ValidationException::withMessages(['jumlah' => 'Batch sudah kedaluwarsa dan tidak boleh dimutasi.']);
            }

            $asal = Cabang::withTrashed()->whereKey($batch->cabang_id)->value('nama');
            $referensi = 'MUTASI-'.now()->format('YmdHis').'-'.$batch->id;

            $batch->jumlah = round($batch->jumlah - $jumlah, 3);
            $batch->save();
            $this->catat($obat, $batch->cabang_id, $batch, JenisMutasi::Keluar, -$jumlah, $user, $referensi,
                trim("Mutasi ke {$tujuan->nama}. ".($keterangan ?? '')));

            $masuk = $this->terima($obat, $tujuan->id, $jumlah, $batch->no_batch, $batch->kedaluwarsa, $user,
                trim("Mutasi dari {$asal}. ".($keterangan ?? '')));
            StokMutasi::where('batch_id', $masuk->id)->latest('id')->first()?->update(['referensi' => $referensi]);

            return $batch;
        });
    }

    /** Buang batch kedaluwarsa: sisa isinya dikeluarkan dengan jejak di kartu stok. */
    public function buangKedaluwarsa(StokBatch $batch, User $user, ?string $keterangan = null): StokBatch
    {
        return DB::transaction(function () use ($batch, $user, $keterangan) {
            $batch = StokBatch::withoutGlobalScope('cabang')->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($batch->jumlah <= 0) {
                throw ValidationException::withMessages(['jumlah' => 'Batch ini sudah kosong.']);
            }

            $obat = Obat::withTrashed()->whereKey($batch->obat_id)->firstOrFail();
            $dibuang = $batch->jumlah;

            $batch->jumlah = 0;
            $batch->save();

            $this->catat($obat, $batch->cabang_id, $batch, JenisMutasi::Keluar, -$dibuang, $user, null,
                $keterangan ?? 'Dibuang: kedaluwarsa');

            return $batch;
        });
    }

    /**
     * Batch yang sudah atau akan kedaluwarsa dalam `$hari` hari ke depan (alert IN-05).
     *
     * @return Collection<int, StokBatch>
     */
    public function akanKedaluwarsa(?int $cabangId, int $hari = 30): Collection
    {
        return StokBatch::withoutGlobalScope('cabang')
            ->with('obat:id,kode,nama,satuan')
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->where('jumlah', '>', 0)
            ->whereNotNull('kedaluwarsa')
            ->whereDate('kedaluwarsa', '<=', today()->addDays($hari))
            ->urutFefo()
            ->get();
    }

    /** Total stok satu obat lintas cabang; menjaga `obats.stok` tetap sinkron dengan batch. */
    public function segarkanTotal(Obat $obat): void
    {
        $total = (float) StokBatch::withoutGlobalScope('cabang')
            ->where('obat_id', $obat->id)
            ->sum('jumlah');

        // Update tanpa event: perubahan stok sudah punya jejak sendiri di kartu stok.
        Obat::withTrashed()->whereKey($obat->id)->update(['stok' => $total]);
        $obat->stok = $total;
    }

    /** Obat non-fraksional hanya boleh bilangan bulat (IN-03). */
    private function pastikanJumlahSah(Obat $obat, float $jumlah): void
    {
        if (! $obat->fraksional && abs($jumlah - round($jumlah)) > self::EPSILON) {
            throw ValidationException::withMessages([
                'jumlah' => "{$obat->nama} tidak bisa dipakai sebagian. Isi jumlah dalam bilangan bulat {$obat->satuan}.",
            ]);
        }
    }

    private function catat(
        Obat $obat,
        int $cabangId,
        StokBatch $batch,
        JenisMutasi $jenis,
        float $delta,
        User $user,
        ?string $referensi,
        ?string $keterangan,
    ): StokMutasi {
        $this->segarkanTotal($obat);

        return StokMutasi::create([
            'obat_id' => $obat->id,
            'cabang_id' => $cabangId,
            'batch_id' => $batch->id,
            'jenis' => $jenis,
            'jumlah' => round($delta, 3),
            'stok_akhir' => $obat->stok,
            'referensi' => $referensi,
            'keterangan' => $keterangan,
            'user_id' => $user->id,
        ]);
    }

    /** Angka desimal tanpa nol berlebih, untuk pesan error. */
    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 3, '.', ''), '0'), '.');
    }
}
