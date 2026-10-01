<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Izin RME estetika. `rme.tindakan` (catatan tindakan & informed consent) untuk tenaga yang melakukan tindakan.
 * `rme.terbatas` (rekam medis berakses terbatas, mis. IMS) sengaja tidak diberikan ke peran mana pun selain
 * administrator: tim yang menangani kunjungan itu tetap bisa membukanya, peran lain diberi lewat menu Peran & Izin.
 */
return new class extends Migration
{
    private const PERAN = ['perawat', 'dokter', 'terapis'];

    public function up(): void
    {
        foreach (self::PERAN as $kode) {
            $peranId = DB::table('perans')->where('kode', $kode)->value('id');

            if ($peranId) {
                DB::table('peran_izins')->insertOrIgnore([['peran_id' => $peranId, 'izin' => 'rme.tindakan']]);
            }
        }
    }

    public function down(): void
    {
        DB::table('peran_izins')->whereIn('izin', ['rme.tindakan', 'rme.terbatas'])->delete();
    }
};
