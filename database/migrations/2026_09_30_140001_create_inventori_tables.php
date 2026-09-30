<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventori: batch & kedaluwarsa per cabang (IN-01), potong BHP otomatis (IN-02),
 * satuan fraksional & pelacakan vial terbuka (IN-03).
 *
 * `obats.stok` tetap ada sebagai total lintas cabang (dipakai dashboard & alert stok minimum),
 * tetapi kini merupakan ringkasan dari `stok_batches` yang dijaga InventoriService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obats', function (Blueprint $table) {
            // Satuan fraksional: 1 vial 100U dipakai beberapa pasien, filler per ml (IN-03).
            $table->boolean('fraksional')->default(false)->after('satuan')
                ->comment('Boleh dipakai sebagian; stok & pemakaian memakai desimal');
            $table->unsignedSmallInteger('jam_pakai_setelah_buka')->nullable()->after('fraksional')
                ->comment('Vial terbuka kedaluwarsa setelah N jam; null = mengikuti tanggal kedaluwarsa batch');
        });

        // Stok disimpan desimal agar pemakaian fraksional tidak dibulatkan.
        Schema::table('obats', function (Blueprint $table) {
            $table->decimal('stok', 12, 3)->default(0)->change();
        });

        // Stok per cabang per batch. FEFO = urut `kedaluwarsa` menaik saat pengeluaran (IN-01).
        Schema::create('stok_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obat_id')->constrained('obats')->restrictOnDelete();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->string('no_batch', 50)->nullable();
            $table->date('kedaluwarsa')->nullable();
            $table->decimal('jumlah', 12, 3)->default(0);
            $table->decimal('jumlah_awal', 12, 3)->default(0);
            // Vial/ampul yang sudah dibuka (IN-03); masa pakainya lebih pendek dari kedaluwarsa batch.
            $table->timestamp('dibuka_at')->nullable();
            $table->timestamp('kedaluwarsa_dibuka_at')->nullable();
            $table->timestamps();

            $table->unique(['obat_id', 'cabang_id', 'no_batch', 'kedaluwarsa'], 'stok_batches_unik');
            $table->index(['obat_id', 'cabang_id', 'kedaluwarsa']);
        });

        Schema::table('stok_mutasis', function (Blueprint $table) {
            $table->foreignId('cabang_id')->nullable()->after('obat_id')->constrained('cabangs')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->after('cabang_id')->constrained('stok_batches')->nullOnDelete();

            $table->index(['obat_id', 'cabang_id']);
        });

        Schema::table('stok_mutasis', function (Blueprint $table) {
            $table->decimal('jumlah', 12, 3)->change();
            $table->decimal('stok_akhir', 12, 3)->change();
        });

        // Pemakaian BHP nyata per tindakan dalam satu kunjungan (IN-02).
        // Baris dibuat dari BHP standar treatment, lalu boleh dikoreksi petugas sesuai pemakaian aktual.
        Schema::create('kunjungan_tindakan_bhps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kunjungan_tindakan_id')->constrained('kunjungan_tindakans')->cascadeOnDelete();
            $table->foreignId('obat_id')->constrained('obats')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stok_batches')->nullOnDelete();
            $table->decimal('jumlah_standar', 12, 3)->default(0)->comment('Dari katalog treatment, sebagai pembanding (LP-04)');
            $table->decimal('jumlah', 12, 3)->comment('Pemakaian aktual yang memotong stok');
            $table->boolean('stok_dipotong')->default(false);
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kunjungan_tindakan_id', 'obat_id']);
            $table->index('obat_id');
        });

        // Stok lama (satu angka global) dipindahkan ke batch tanpa nomor di cabang pertama,
        // supaya kartu stok & total tetap konsisten setelah migrasi.
        $cabangId = DB::table('cabangs')->orderBy('id')->value('id');

        if ($cabangId) {
            $now = now();

            $batches = DB::table('obats')->where('stok', '>', 0)->get(['id', 'stok'])
                ->map(fn ($obat) => [
                    'obat_id' => $obat->id,
                    'cabang_id' => $cabangId,
                    'no_batch' => null,
                    'kedaluwarsa' => null,
                    'jumlah' => $obat->stok,
                    'jumlah_awal' => $obat->stok,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

            foreach (array_chunk($batches, 100) as $chunk) {
                DB::table('stok_batches')->insert($chunk);
            }

            DB::table('stok_mutasis')->whereNull('cabang_id')->update(['cabang_id' => $cabangId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungan_tindakan_bhps');

        Schema::table('stok_mutasis', function (Blueprint $table) {
            $table->integer('jumlah')->change();
            $table->integer('stok_akhir')->change();
        });

        Schema::table('stok_mutasis', function (Blueprint $table) {
            $table->dropIndex(['obat_id', 'cabang_id']);
            $table->dropConstrainedForeignId('batch_id');
            $table->dropConstrainedForeignId('cabang_id');
        });

        Schema::dropIfExists('stok_batches');

        Schema::table('obats', function (Blueprint $table) {
            $table->integer('stok')->default(0)->change();
            $table->dropColumn(['fraksional', 'jam_pakai_setelah_buka']);
        });
    }
};
