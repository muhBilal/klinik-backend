<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kasir (PRD BL-02, BL-03, BL-05, BL-06, AD-04 pajak) dan temuan teknis 8.3 #3 & #7.
 *
 * Perubahan utama: satu kunjungan boleh punya lebih dari satu tagihan/resep, dan tagihan boleh berdiri
 * sendiri tanpa kunjungan (paket, deposit, penjualan produk). Pembayaran pindah ke tabel sendiri
 * sehingga satu tagihan bisa dibayar dengan beberapa metode (split payment).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Shift kas: buka/tutup dengan rekap per metode bayar (BL-05).
        Schema::create('shift_kas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->foreignId('kasir_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('dibuka_at');
            $table->timestamp('ditutup_at')->nullable();
            $table->unsignedBigInteger('modal_awal')->default(0);
            $table->unsignedBigInteger('kas_fisik')->nullable()->comment('Uang tunai yang dihitung saat tutup');
            $table->bigInteger('selisih')->nullable()->comment('kas_fisik - (modal_awal + tunai masuk); negatif = kurang');
            $table->string('catatan')->nullable();
            $table->timestamps();

            $table->index(['cabang_id', 'kasir_id', 'ditutup_at']);
        });

        Schema::table('tagihans', function (Blueprint $table) {
            // Tagihan tanpa kunjungan (paket/produk/deposit) menyimpan pasien langsung.
            $table->foreignId('pasien_id')->nullable()->after('kunjungan_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->after('kasir_id')->constrained('shift_kas')->nullOnDelete();
            $table->unsignedBigInteger('pajak')->default(0)->after('diskon');
            $table->unsignedInteger('pajak_persen')->default(0)->after('pajak')->comment('Snapshot tarif pajak saat tagihan dibuat');
            $table->string('keterangan')->nullable()->after('status');
            $table->timestamp('dibatalkan_at')->nullable();
            $table->foreignId('dibatalkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_batal')->nullable();
            $table->softDeletes();
        });

        // Satu kunjungan boleh punya beberapa tagihan (8.3 #3); kunjungan_id juga boleh kosong.
        Schema::table('tagihans', function (Blueprint $table) {
            $table->dropUnique(['kunjungan_id']);
        });
        Schema::table('tagihans', function (Blueprint $table) {
            $table->unsignedBigInteger('kunjungan_id')->nullable()->change();
        });
        Schema::table('tagihans', function (Blueprint $table) {
            $table->index('kunjungan_id');
            $table->index('pasien_id');
        });

        // Satu kunjungan boleh punya beberapa resep (mis. resep tambahan setelah tindakan).
        Schema::table('reseps', function (Blueprint $table) {
            $table->dropUnique(['kunjungan_id']);
        });
        Schema::table('reseps', function (Blueprint $table) {
            $table->index('kunjungan_id');
            $table->timestamp('dibatalkan_at')->nullable();
            $table->foreignId('dibatalkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_batal')->nullable();
        });

        // Split payment (BL-03): satu tagihan, beberapa baris pembayaran.
        Schema::create('pembayarans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_id')->constrained('tagihans')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shift_kas')->nullOnDelete();
            $table->string('metode', 20);
            $table->unsignedBigInteger('jumlah');
            $table->string('referensi', 100)->nullable()->comment('No. approval EDC / QRIS / transfer');
            $table->foreignId('kasir_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dibayar_at');
            // Refund dicatat sebagai pembatalan baris pembayaran, bukan baris bernilai negatif (BL-06).
            $table->timestamp('dikembalikan_at')->nullable();
            $table->foreignId('dikembalikan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_refund')->nullable();
            $table->timestamps();

            $table->index(['tagihan_id', 'dikembalikan_at']);
            $table->index(['shift_id', 'metode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayarans');

        Schema::table('reseps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dibatalkan_oleh');
            $table->dropColumn(['dibatalkan_at', 'alasan_batal']);
            $table->dropIndex(['kunjungan_id']);
            $table->unique('kunjungan_id');
        });

        Schema::table('tagihans', function (Blueprint $table) {
            $table->dropIndex(['kunjungan_id']);
            $table->dropIndex(['pasien_id']);
        });
        Schema::table('tagihans', function (Blueprint $table) {
            $table->unsignedBigInteger('kunjungan_id')->nullable(false)->change();
        });
        Schema::table('tagihans', function (Blueprint $table) {
            $table->unique('kunjungan_id');
            $table->dropConstrainedForeignId('pasien_id');
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('dibatalkan_oleh');
            $table->dropColumn(['pajak', 'pajak_persen', 'keterangan', 'dibatalkan_at', 'alasan_batal']);
            $table->dropSoftDeletes();
        });

        Schema::dropIfExists('shift_kas');
    }
};
