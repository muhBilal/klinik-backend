<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Izin booking untuk peran bawaan (PRD BK-01). Admin memakai `akses_penuh`, jadi tidak perlu baris.
 */
return new class extends Migration
{
    private const IZIN = [
        'pendaftaran' => ['booking.lihat', 'booking.kelola'],
        'perawat' => ['booking.lihat'],
        'dokter' => ['booking.lihat'],
        'terapis' => ['booking.lihat'],
        'manajer' => ['booking.lihat', 'jadwal.kelola'],
    ];

    public function up(): void
    {
        foreach (self::IZIN as $kode => $izins) {
            $peranId = DB::table('perans')->where('kode', $kode)->value('id');

            if (! $peranId) {
                continue;
            }

            DB::table('peran_izins')->insertOrIgnore(
                array_map(fn ($izin) => ['peran_id' => $peranId, 'izin' => $izin], $izins),
            );
        }
    }

    public function down(): void
    {
        DB::table('peran_izins')->whereIn('izin', ['booking.lihat', 'booking.kelola', 'jadwal.kelola'])->delete();
    }
};
