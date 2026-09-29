<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generator nomor berurutan berbasis tabel `counters`.
 * Baris counter dikunci (SELECT ... FOR UPDATE) sehingga aman dipakai bersamaan.
 */
class NomorUrutService
{
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

    public function noRekamMedis(): string
    {
        return str_pad((string) $this->next('rm'), 6, '0', STR_PAD_LEFT);
    }

    public function noAntrian(int $poliId, CarbonInterface $tanggal): int
    {
        return $this->next("antrian:{$poliId}:{$tanggal->format('Ymd')}");
    }

    public function noRegistrasi(CarbonInterface $tanggal): string
    {
        return $this->harian('REG', $tanggal);
    }

    public function noResep(CarbonInterface $tanggal): string
    {
        return $this->harian('RSP', $tanggal);
    }

    public function noTagihan(CarbonInterface $tanggal): string
    {
        return $this->harian('INV', $tanggal);
    }

    private function harian(string $prefix, CarbonInterface $tanggal): string
    {
        $ymd = $tanggal->format('Ymd');
        $urut = $this->next(strtolower($prefix).":{$ymd}");

        return $prefix.$ymd.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }
}
