<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paket dipesan dokter/terapis dari pemeriksaan (TR-02): `paket_pasiens.kunjungan_id` = kunjungan tempat paket dipesan. Paket itu
 * ditagihkan bersama tagihan kunjungan (bukan tagihan mandiri), boleh dipakai sesi pertamanya di kunjungan yang sama, dan aktif saat
 * tagihan kunjungan lunas. Penjualan langsung di kasir tetap tanpa kunjungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paket_pasiens', function (Blueprint $table) {
            $table->foreignId('kunjungan_id')->nullable()->after('tagihan_id')->constrained('kunjungans')->nullOnDelete()
                ->comment('Kunjungan tempat paket dipesan dokter/terapis (ditagihkan bersama tagihan kunjungan)');
        });
    }

    public function down(): void
    {
        Schema::table('paket_pasiens', fn (Blueprint $table) => $table->dropConstrainedForeignId('kunjungan_id'));
    }
};
