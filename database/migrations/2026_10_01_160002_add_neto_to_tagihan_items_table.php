<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai bersih per baris tagihan, dihitung sekali saat lunas (`KasirService::bayar`): potongan promo hanya menimpa baris yang memenuhi
 * syarat promo, diskon manual dibagi sebanding sisa tiap baris. Satu sumber untuk nilai paket, komisi (dasar neto) & laporan penjualan —
 * tagihan kunjungan kini bisa memuat paket bersama konsultasi & tindakan. Tagihan lama (null) memakai alokasi proporsional seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan_items', function (Blueprint $table) {
            $table->unsignedBigInteger('neto')->nullable()->after('subtotal')
                ->comment('Nilai bersih baris sebelum pajak setelah potongan promo & diskon manual; diisi saat tagihan lunas');
        });
    }

    public function down(): void
    {
        Schema::table('tagihan_items', function (Blueprint $table) {
            $table->dropColumn('neto');
        });
    }
};
