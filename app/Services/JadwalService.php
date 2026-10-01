<?php

namespace App\Services;

use App\Enums\TipePengecualian;
use App\Models\Appointment;
use App\Models\JadwalPengecualian;
use App\Models\JadwalPraktik;
use App\Models\SumberDaya;
use App\Models\Tindakan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Jadwal praktik & slot yang tersedia (PRD BK-02, BK-03).
 *
 * Jam kerja efektif satu petugas pada satu tanggal = pola mingguan (`jadwal_praktiks`) ditambah
 * jadwal tambahan, lalu dikurangi cuti. Slot dihasilkan dari jam kerja itu, dibuang yang bentrok.
 */
class JadwalService
{
    /** Jarak antar awal slot yang ditawarkan (menit). */
    private const LANGKAH_MENIT = 15;

    /**
     * Rentang jam kerja petugas pada satu tanggal, sudah digabung & dikurangi cuti.
     *
     * @return list<array{mulai: CarbonImmutable, selesai: CarbonImmutable}> terurut, tidak saling beririsan
     */
    public function jamKerja(int $cabangId, int $userId, CarbonInterface $tanggal): array
    {
        $tanggal = CarbonImmutable::parse($tanggal)->startOfDay();

        $pengecualian = JadwalPengecualian::withoutGlobalScope('cabang')
            ->where('cabang_id', $cabangId)->where('user_id', $userId)
            ->whereDate('tanggal', $tanggal)
            ->get();

        // Cuti sehari penuh mengosongkan jadwal, termasuk jadwal tambahan pada hari itu.
        if ($pengecualian->contains(fn ($p) => $p->tipe === TipePengecualian::Cuti && $p->sehariPenuh())) {
            return [];
        }

        $rutin = JadwalPraktik::withoutGlobalScope('cabang')
            ->where('cabang_id', $cabangId)->where('user_id', $userId)
            ->where('hari', $tanggal->dayOfWeek)->where('is_active', true)
            ->get();

        $rentang = $rutin
            ->map(fn ($j) => $this->rentang($tanggal, $j->jam_mulai, $j->jam_selesai))
            ->merge($pengecualian
                ->where('tipe', TipePengecualian::Tambahan)
                ->map(fn ($p) => $this->rentang($tanggal, $p->jam_mulai, $p->jam_selesai)))
            ->filter()
            ->values();

        $cuti = $pengecualian
            ->where('tipe', TipePengecualian::Cuti)
            ->map(fn ($p) => $this->rentang($tanggal, $p->jam_mulai, $p->jam_selesai))
            ->filter()
            ->values();

        return $this->kurangi($this->gabung($rentang), $cuti);
    }

    /**
     * Slot mulai yang masih kosong untuk satu petugas, dengan durasi `$menit` (treatment + buffer).
     * Slot yang sudah lewat tidak ditawarkan.
     *
     * @param  list<int>  $sumberDayaIds  ruang/alat yang ikut dipakai; slot dibuang bila salah satunya terpakai
     * @param  int|null  $kecuali  booking yang diabaikan saat cek bentrok (reschedule booking itu sendiri)
     * @return list<array{mulai: string, selesai: string}>
     */
    public function slotTersedia(int $cabangId, int $userId, CarbonInterface $tanggal, int $menit, array $sumberDayaIds = [], ?int $kecuali = null): array
    {
        if ($menit < 1) {
            return [];
        }

        $sekarang = CarbonImmutable::now();
        $slot = [];

        foreach ($this->jamKerja($cabangId, $userId, $tanggal) as $kerja) {
            for ($mulai = $kerja['mulai']; $mulai->addMinutes($menit) <= $kerja['selesai']; $mulai = $mulai->addMinutes(self::LANGKAH_MENIT)) {
                $selesai = $mulai->addMinutes($menit);

                if ($mulai < $sekarang) {
                    continue;
                }

                if ($this->bentrok($cabangId, $userId, $sumberDayaIds, $mulai, $selesai, $kecuali) === null) {
                    $slot[] = ['mulai' => $mulai->toDateTimeString(), 'selesai' => $selesai->toDateTimeString()];
                }
            }
        }

        return $slot;
    }

    /**
     * Alasan bentrok pertama yang ditemukan, atau null bila slot bebas.
     * Memeriksa petugas dan setiap sumber daya; `$kecuali` melewati satu appointment (dipakai saat reschedule).
     *
     * @param  list<int>  $sumberDayaIds
     */
    public function bentrok(int $cabangId, ?int $userId, array $sumberDayaIds, CarbonInterface $mulai, CarbonInterface $selesai, ?int $kecuali = null): ?string
    {
        $dasar = fn () => Appointment::withoutGlobalScope('cabang')
            ->where('cabang_id', $cabangId)
            ->memesanSlot()
            ->beririsan($mulai->toDateTimeString(), $selesai->toDateTimeString())
            ->when($kecuali, fn ($q) => $q->whereKeyNot($kecuali));

        if ($userId && ($lain = $dasar()->where('petugas_id', $userId)->first())) {
            return "Petugas sudah punya booking {$lain->no_booking} pada jam tersebut.";
        }

        foreach ($sumberDayaIds as $id) {
            $lain = $dasar()->whereHas('sumberDayas', fn ($q) => $q->whereKey($id))->first();

            if ($lain) {
                $nama = SumberDaya::withoutGlobalScope('cabang')->withTrashed()->whereKey($id)->value('nama');

                return "{$nama} sudah dipakai booking {$lain->no_booking} pada jam tersebut.";
            }
        }

        return null;
    }

    /**
     * Total durasi treatment (menit) termasuk buffer terakhir — dasar panjang slot (BK-02).
     *
     * @param  Collection<int, Tindakan>  $tindakans
     */
    public function durasiTotal(Collection $tindakans): int
    {
        return (int) $tindakans->sum(fn (Tindakan $t) => $t->durasi_menit + $t->buffer_menit);
    }

    private function rentang(CarbonImmutable $tanggal, ?string $mulai, ?string $selesai): ?array
    {
        if (! $mulai || ! $selesai) {
            return null;
        }

        $awal = $this->jam($tanggal, $mulai);
        $akhir = $this->jam($tanggal, $selesai);

        return $akhir > $awal ? ['mulai' => $awal, 'selesai' => $akhir] : null;
    }

    /** Jam "HH:MM" atau "HH:MM:SS" pada tanggal tertentu. */
    private function jam(CarbonImmutable $tanggal, string $jam): CarbonImmutable
    {
        [$h, $m] = array_pad(explode(':', $jam), 2, '0');

        return $tanggal->setTime((int) $h, (int) $m);
    }

    /**
     * Gabungkan rentang yang beririsan/bersambung menjadi rentang tunggal.
     *
     * @param  Collection<int, array{mulai: CarbonImmutable, selesai: CarbonImmutable}>  $rentang
     * @return list<array{mulai: CarbonImmutable, selesai: CarbonImmutable}>
     */
    private function gabung(Collection $rentang): array
    {
        $hasil = [];

        foreach ($rentang->sortBy(fn ($r) => $r['mulai']->timestamp)->values() as $r) {
            $akhir = array_key_last($hasil);

            if ($akhir !== null && $r['mulai'] <= $hasil[$akhir]['selesai']) {
                $hasil[$akhir]['selesai'] = max($hasil[$akhir]['selesai'], $r['selesai']);

                continue;
            }

            $hasil[] = $r;
        }

        return $hasil;
    }

    /**
     * Potong `$rentang` dengan setiap rentang di `$potong` (cuti sebagian hari).
     *
     * @param  list<array{mulai: CarbonImmutable, selesai: CarbonImmutable}>  $rentang
     * @param  Collection<int, array{mulai: CarbonImmutable, selesai: CarbonImmutable}>  $potong
     * @return list<array{mulai: CarbonImmutable, selesai: CarbonImmutable}>
     */
    private function kurangi(array $rentang, Collection $potong): array
    {
        foreach ($potong as $p) {
            $sisa = [];

            foreach ($rentang as $r) {
                if ($p['selesai'] <= $r['mulai'] || $p['mulai'] >= $r['selesai']) {
                    $sisa[] = $r;

                    continue;
                }

                if ($p['mulai'] > $r['mulai']) {
                    $sisa[] = ['mulai' => $r['mulai'], 'selesai' => $p['mulai']];
                }
                if ($p['selesai'] < $r['selesai']) {
                    $sisa[] = ['mulai' => $p['selesai'], 'selesai' => $r['selesai']];
                }
            }

            $rentang = $sisa;
        }

        return $rentang;
    }
}
