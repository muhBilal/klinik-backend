<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generator nomor berurutan berbasis tabel `counters`.
 * Baris counter dikunci (SELECT ... FOR UPDATE) sehingga aman dipakai bersamaan.
 * Prefix dokumen diambil dari pengaturan `penomoran.*`; key counter tetap per jenis dokumen.
 */
class NomorUrutService
{
    public function __construct(private PengaturanService $pengaturan) {}

    public function next(string $key): int
    {
        return DB::transaction(function () use ($key) {
            DB::table('counters')->insertOrIgnore(['key' => $key, 'value' => 0]);

            $current = DB::table('counters')->where('key', $key)->lockForUpdate()->value('value');
            $next = $current + 1;

            DB::table('counters')->where('key', $key)->update(['value' => $next]);

            return $next;
        });
    }

    /** No. RM berlaku lintas cabang (master pasien pusat). */
    public function noRekamMedis(): string
    {
        return str_pad((string) $this->next('rm'), 6, '0', STR_PAD_LEFT);
    }

    /** Nomor antrian berurutan per cabang, per poli, per hari. */
    public function noAntrian(int $cabangId, int $poliId, CarbonInterface $tanggal): int
    {
        return $this->next("antrian:{$cabangId}:{$poliId}:{$tanggal->format('Ymd')}");
    }

    public function noRegistrasi(CarbonInterface $tanggal): string
    {
        return $this->harian('reg', $this->pengaturan->get('penomoran.prefix_registrasi'), $tanggal);
    }

    public function noResep(CarbonInterface $tanggal): string
    {
        return $this->harian('rsp', $this->pengaturan->get('penomoran.prefix_resep'), $tanggal);
    }

    public function noTagihan(CarbonInterface $tanggal): string
    {
        return $this->harian('inv', $this->pengaturan->get('penomoran.prefix_tagihan'), $tanggal);
    }

    private function harian(string $jenis, string $prefix, CarbonInterface $tanggal): string
    {
        $ymd = $tanggal->format('Ymd');
        $urut = $this->next("{$jenis}:{$ymd}");

        return $prefix.$ymd.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }
}
