<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan diskon di atas batas peran (PRD BL-02, PRD v2 9.6): penyetuju dicatat di tagihan,
 * izin baru `kasir.diskon` untuk manajer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihans', function (Blueprint $table) {
            $table->foreignId('diskon_disetujui_oleh')->nullable()->after('diskon')->constrained('users')->nullOnDelete();
        });

        $peranId = DB::table('perans')->where('kode', 'manajer')->value('id');

        if ($peranId) {
            DB::table('peran_izins')->insertOrIgnore(['peran_id' => $peranId, 'izin' => 'kasir.diskon']);
        }
    }

    public function down(): void
    {
        DB::table('peran_izins')->where('izin', 'kasir.diskon')->delete();

        Schema::table('tagihans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diskon_disetujui_oleh');
        });
    }
};
