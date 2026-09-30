<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Izin inventori (PRD IN-01 s.d. IN-03). Terapis & perawat ikut mendapat izin karena merekalah
 * yang mengoreksi pemakaian BHP aktual setelah tindakan.
 */
return new class extends Migration
{
    private const PERAN = ['apoteker', 'perawat', 'dokter', 'terapis'];

    public function up(): void
    {
        foreach (self::PERAN as $kode) {
            $peranId = DB::table('perans')->where('kode', $kode)->value('id');

            if ($peranId) {
                DB::table('peran_izins')->insertOrIgnore([['peran_id' => $peranId, 'izin' => 'inventori.kelola']]);
            }
        }
    }

    public function down(): void
    {
        DB::table('peran_izins')->where('izin', 'inventori.kelola')->delete();
    }
};
