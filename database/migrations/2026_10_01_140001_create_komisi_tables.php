<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Komisi & jasa medis (PRD KM-01, KM-03, AN-03 · PRD v2 #4).
 *
 * - `kunjungan_tindakan_petugas`: petugas tambahan per tindakan (asisten, terapis kedua) selain pelaksana utama `petugas_id`.
 * - `tagihan_items.kunjungan_tindakan_id`: baris tagihan → tindakan kunjungan (dasar komisi & laporan per dokter).
 * - `aturan_komisis`: persen/nominal per peran, untuk treatment / kategori / semua, opsional per cabang.
 * - `komisis`: kejadian komisi (bayar = +, refund = −) per petugas, ber-periode; rekap = jumlah per periode.
 * - `periode_komisis`: status periode per cabang; `disetujui` = terkunci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kunjungan_tindakan_petugas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kunjungan_tindakan_id')->constrained('kunjungan_tindakans')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('peran', 20);
            $table->timestamps();

            $table->unique(['kunjungan_tindakan_id', 'user_id']);
        });

        Schema::table('tagihan_items', function (Blueprint $table) {
            $table->foreignId('kunjungan_tindakan_id')->nullable()->after('tindakan_id')->constrained('kunjungan_tindakans')->nullOnDelete();
        });

        Schema::create('aturan_komisis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tindakan_id')->nullable()->constrained('tindakans')->cascadeOnDelete();
            $table->foreignId('kategori_id')->nullable()->constrained('kategori_tindakans')->cascadeOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->cascadeOnDelete();
            $table->string('peran', 20);
            $table->string('jenis', 10);
            $table->decimal('nilai', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->string('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['peran', 'tindakan_id']);
        });

        Schema::create('periode_komisis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->char('periode', 7);
            $table->string('status', 15)->default('draf');
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disetujui_at')->nullable();
            $table->string('catatan')->nullable();
            $table->timestamps();

            $table->unique(['cabang_id', 'periode']);
        });

        Schema::create('komisis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->char('periode', 7);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('peran', 20);
            $table->string('sumber', 15);
            $table->foreignId('tagihan_id')->constrained('tagihans')->restrictOnDelete();
            $table->foreignId('tagihan_item_id')->nullable()->constrained('tagihan_items')->nullOnDelete();
            $table->foreignId('kunjungan_tindakan_id')->nullable()->constrained('kunjungan_tindakans')->nullOnDelete();
            $table->foreignId('tindakan_id')->nullable()->constrained('tindakans')->nullOnDelete();
            $table->foreignId('aturan_id')->nullable()->constrained('aturan_komisis')->nullOnDelete();
            $table->bigInteger('dasar');
            $table->string('jenis', 10);
            $table->decimal('nilai_aturan', 12, 2);
            $table->unsignedTinyInteger('dibagi')->default(1);
            $table->bigInteger('jumlah');
            $table->string('keterangan')->nullable();
            $table->timestamps();

            $table->index(['cabang_id', 'periode', 'user_id']);
            $table->index(['tagihan_id', 'sumber']);
        });

        // Izin baru: kelola aturan & rekap (komisi.kelola), setujui/kunci periode (komisi.setujui)
        $peranId = DB::table('perans')->where('kode', 'manajer')->value('id');
        if ($peranId) {
            DB::table('peran_izins')->insertOrIgnore([
                ['peran_id' => $peranId, 'izin' => 'komisi.kelola'],
                ['peran_id' => $peranId, 'izin' => 'komisi.setujui'],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('peran_izins')->whereIn('izin', ['komisi.kelola', 'komisi.setujui'])->delete();

        Schema::dropIfExists('komisis');
        Schema::dropIfExists('periode_komisis');
        Schema::dropIfExists('aturan_komisis');
        Schema::table('tagihan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kunjungan_tindakan_id');
        });
        Schema::dropIfExists('kunjungan_tindakan_petugas');
    }
};
