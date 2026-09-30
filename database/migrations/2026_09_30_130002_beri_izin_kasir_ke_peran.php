<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Izin kasir baru (PRD BL-05, BL-06). Void/refund sengaja tidak diberikan ke kasir biasa:
 * butuh persetujuan manajer/admin.
 */
return new class extends Migration
{
    private const IZIN = [
        'kasir' => ['kasir.shift'],
        'manajer' => ['kasir.void'],
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
        DB::table('peran_izins')->whereIn('izin', ['kasir.void', 'kasir.shift'])->delete();
    }
};
