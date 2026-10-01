<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paket multi-sesi (PRD TR-02, BL-01) dan voucher & promo (TR-06).
 *
 * - `pakets` + `paket_items`: katalog paket (beberapa treatment × jumlah sesi, harga paket, masa berlaku).
 * - `paket_pasiens` + `paket_pasien_items`: paket yang dibeli pasien. Dijual lewat tagihan mandiri; aktif saat tagihan lunas.
 *   Nilai bersih paket dialokasikan per sesi (`nilai_per_sesi`) sebagai dasar pendapatan diterima di muka (LP-03).
 * - `kunjungan_tindakans.paket_pasien_item_id`: tindakan kunjungan memakai sesi paket → ditagih Rp 0. Sisa sesi = jumlah sesi −
 *   tindakan kunjungan (bukan batal) yang memakainya; tidak ada counter terpisah yang bisa selisih.
 * - `promos` + `promo_pemakaians`: kode promo/voucher dengan periode, kuota, kuota per pasien, minimum transaksi, cabang &
 *   treatment/paket tertentu. Dipasang ke tagihan (`tagihans.promo_id`, `diskon_promo`) sebelum dibayar.
 * - `tagihan_items.tindakan_id` / `paket_id`: dasar promo per treatment/paket dan laporan penjualan per treatment.
 */
return new class extends Migration
{
    private const IZIN_BARU = ['manajer' => 'promo.kelola', 'marketing' => 'promo.kelola', 'kasir' => 'pasien.lihat'];

    public function up(): void
    {
        Schema::create('pakets', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();
            $table->string('nama', 150);
            $table->text('deskripsi')->nullable();
            $table->unsignedInteger('harga')->comment('Harga jual paket');
            $table->unsignedSmallInteger('masa_berlaku_hari')->nullable()->comment('Sejak paket aktif (lunas); null = tanpa batas');
            $table->boolean('lintas_cabang')->default(true)->comment('false = hanya dipakai di cabang pembelian');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('paket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paket_id')->constrained('pakets')->cascadeOnDelete();
            $table->foreignId('tindakan_id')->constrained('tindakans')->restrictOnDelete();
            $table->unsignedSmallInteger('jumlah_sesi');
            $table->timestamps();

            $table->unique(['paket_id', 'tindakan_id']);
        });

        Schema::create('paket_pasiens', function (Blueprint $table) {
            $table->id();
            $table->string('no_paket', 30)->unique();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('paket_id')->constrained('pakets')->restrictOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete()->comment('Cabang pembelian');
            $table->foreignId('tagihan_id')->nullable()->constrained('tagihans')->nullOnDelete()->comment('Tagihan penjualan');
            $table->string('nama', 150)->comment('Snapshot nama paket');
            $table->unsignedInteger('harga')->comment('Snapshot harga jual');
            $table->unsignedInteger('nilai')->nullable()->comment('Nilai bersih setelah diskon tagihan, diisi saat lunas');
            $table->string('status', 20)->default('menunggu_bayar');
            $table->boolean('lintas_cabang')->default(true);
            $table->unsignedSmallInteger('masa_berlaku_hari')->nullable();
            $table->timestamp('aktif_at')->nullable();
            $table->date('berlaku_sampai')->nullable();
            $table->string('catatan')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            // Pengalihan ke pasien lain (sesuai kebijakan klinik)
            $table->foreignId('dialihkan_dari_id')->nullable()->constrained('paket_pasiens')->nullOnDelete();
            $table->timestamp('dialihkan_at')->nullable();
            $table->foreignId('dialihkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_alih', 500)->nullable();
            // Pengembalian dana sisa paket (prorata, sesuai kebijakan klinik)
            $table->unsignedInteger('refund_nominal')->nullable();
            $table->string('refund_metode', 20)->nullable();
            $table->string('refund_referensi', 100)->nullable();
            $table->foreignId('refund_shift_id')->nullable()->constrained('shift_kas')->nullOnDelete();
            $table->timestamp('direfund_at')->nullable();
            $table->foreignId('direfund_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_refund', 500)->nullable();
            $table->timestamps();

            $table->index(['pasien_id', 'status']);
        });

        Schema::create('paket_pasien_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paket_pasien_id')->constrained('paket_pasiens')->cascadeOnDelete();
            $table->foreignId('tindakan_id')->constrained('tindakans')->restrictOnDelete();
            $table->unsignedSmallInteger('jumlah_sesi');
            $table->unsignedInteger('nilai_per_sesi')->default(0)->comment('Alokasi nilai bersih paket per sesi (pendapatan diterima di muka)');
            $table->timestamps();
        });

        Schema::table('kunjungan_tindakans', function (Blueprint $table) {
            $table->foreignId('paket_pasien_item_id')->nullable()->after('rencana_item_id')->constrained('paket_pasien_items')->nullOnDelete();
        });

        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique()->comment('Huruf besar, dimasukkan kasir/pasien');
            $table->string('nama', 150);
            $table->text('deskripsi')->nullable();
            $table->string('jenis', 10)->comment('persen / nominal');
            $table->unsignedInteger('nilai')->comment('Persen (1-100) atau rupiah');
            $table->unsignedInteger('maks_potongan')->nullable()->comment('Batas potongan untuk jenis persen');
            $table->unsignedInteger('min_transaksi')->default(0)->comment('Minimum total tagihan');
            $table->date('mulai');
            $table->date('berakhir')->nullable();
            $table->unsignedInteger('kuota')->nullable()->comment('Total pemakaian; null = tanpa batas');
            $table->unsignedInteger('kuota_per_pasien')->nullable();
            $table->json('cabang_ids')->nullable()->comment('null = semua cabang');
            $table->json('tindakan_ids')->nullable()->comment('Potongan hanya untuk item treatment ini');
            $table->json('paket_ids')->nullable()->comment('Potongan hanya untuk item paket ini');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('promo_pemakaians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_id')->constrained('promos')->restrictOnDelete();
            $table->foreignId('tagihan_id')->constrained('tagihans')->cascadeOnDelete();
            $table->foreignId('pasien_id')->nullable()->constrained('pasiens')->nullOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete();
            $table->unsignedInteger('potongan');
            $table->timestamp('dipakai_at');
            $table->timestamp('dibatalkan_at')->nullable()->comment('Tagihan direfund → kuota kembali');
            $table->timestamps();

            $table->index(['promo_id', 'dibatalkan_at']);
        });

        Schema::table('tagihans', function (Blueprint $table) {
            $table->foreignId('promo_id')->nullable()->after('diskon')->constrained('promos')->nullOnDelete();
            $table->unsignedBigInteger('diskon_promo')->default(0)->after('promo_id');
        });

        Schema::table('tagihan_items', function (Blueprint $table) {
            $table->foreignId('tindakan_id')->nullable()->after('kategori')->constrained('tindakans')->nullOnDelete();
            $table->foreignId('paket_id')->nullable()->after('tindakan_id')->constrained('pakets')->nullOnDelete();
        });

        // Izin kelola voucher & promo: manajer dan marketing (peran bawaan non-sistem). Kasir menjual paket ke pasien
        // tertentu → perlu mencari & melihat identitas pasien (pasien.lihat; bukan rekam medis).
        foreach (self::IZIN_BARU as $kode => $izin) {
            if ($peranId = DB::table('perans')->where('kode', $kode)->value('id')) {
                DB::table('peran_izins')->insertOrIgnore(['peran_id' => $peranId, 'izin' => $izin]);
            }
        }
    }

    public function down(): void
    {
        DB::table('peran_izins')->where('izin', 'promo.kelola')->delete();
        DB::table('peran_izins')->where('izin', 'pasien.lihat')
            ->whereIn('peran_id', DB::table('perans')->where('kode', 'kasir')->select('id'))->delete();

        Schema::table('tagihan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paket_id');
            $table->dropConstrainedForeignId('tindakan_id');
        });
        Schema::table('tagihans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_id');
            $table->dropColumn('diskon_promo');
        });
        Schema::dropIfExists('promo_pemakaians');
        Schema::dropIfExists('promos');
        Schema::table('kunjungan_tindakans', fn (Blueprint $table) => $table->dropConstrainedForeignId('paket_pasien_item_id'));
        Schema::dropIfExists('paket_pasien_items');
        Schema::dropIfExists('paket_pasiens');
        Schema::dropIfExists('paket_items');
        Schema::dropIfExists('pakets');
    }
};
