<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Komisi & jasa medis (PRD KM-01, KM-03; aturan komisi TR-01).
 *
 * - `aturan_komisis`: mesin aturan yang dikonfigurasi klinik (bukan hard-code — tabel risiko PRD): sumber `tindakan` (per treatment /
 *   kategori / semua treatment) atau `konsultasi` (per poli / semua poli), per peran dalam tindakan (dokter, terapis = pelaksana,
 *   asisten), persen atau nominal, opsional khusus satu cabang.
 * - `kunjungan_tindakans.asisten_id`: peran asisten per tindakan (pelaksana = `petugas_id` sejak F1-05, dokter = dokter kunjungan).
 * - `komisi_periodes` + `komisi_barises`: rekap per cabang per periode; dihitung dari tagihan kunjungan yang lunas, bisa dihitung ulang
 *   selama draf, dikunci saat disetujui. Baris menyimpan dasar, aturan & nilai yang dipakai (snapshot) sehingga slip tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aturan_komisis', function (Blueprint $table) {
            $table->id();
            $table->string('sumber', 15)->comment('tindakan / konsultasi');
            $table->foreignId('tindakan_id')->nullable()->constrained('tindakans')->cascadeOnDelete();
            $table->foreignId('kategori_tindakan_id')->nullable()->constrained('kategori_tindakans')->cascadeOnDelete();
            $table->foreignId('poli_id')->nullable()->constrained('polis')->cascadeOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->cascadeOnDelete()->comment('null = semua cabang');
            $table->string('peran', 10)->comment('dokter / terapis / asisten');
            $table->string('jenis', 10)->comment('persen / nominal');
            $table->decimal('nilai', 12, 2)->comment('Persen (0-100) atau rupiah per unit tindakan');
            $table->string('keterangan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['sumber', 'peran']);
        });

        Schema::table('kunjungan_tindakans', function (Blueprint $table) {
            $table->foreignId('asisten_id')->nullable()->after('petugas_id')->constrained('users')->nullOnDelete()
                ->comment('Asisten tindakan (dasar komisi peran asisten)');
        });

        Schema::create('komisi_periodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->string('nama', 100);
            $table->date('mulai');
            $table->date('selesai');
            $table->string('status', 15)->default('draf')->comment('draf / disetujui');
            $table->string('dasar', 10)->nullable()->comment('Snapshot pengaturan komisi.dasar saat dihitung (bruto / neto)');
            $table->unsignedBigInteger('total')->default(0);
            $table->timestamp('dihitung_at')->nullable();
            $table->foreignId('dihitung_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disetujui_at')->nullable();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('catatan', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cabang_id', 'mulai', 'selesai']);
        });

        Schema::create('komisi_barises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('komisi_periode_id')->constrained('komisi_periodes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('peran', 12)->comment('dokter / terapis / asisten / penyesuaian');
            $table->string('sumber', 15)->comment('tindakan / konsultasi / penyesuaian');
            $table->foreignId('kunjungan_id')->nullable()->constrained('kunjungans')->nullOnDelete();
            $table->foreignId('kunjungan_tindakan_id')->nullable()->constrained('kunjungan_tindakans')->nullOnDelete();
            $table->foreignId('tagihan_id')->nullable()->constrained('tagihans')->nullOnDelete();
            $table->foreignId('aturan_komisi_id')->nullable()->constrained('aturan_komisis')->nullOnDelete();
            $table->date('tanggal');
            $table->string('deskripsi');
            $table->bigInteger('dasar')->default(0)->comment('Nilai dasar perhitungan (rupiah)');
            $table->string('jenis', 10)->nullable();
            $table->decimal('nilai', 12, 2)->nullable();
            $table->bigInteger('komisi')->comment('Rupiah; penyesuaian boleh negatif');
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete()->comment('Diisi untuk penyesuaian manual');
            $table->timestamps();

            $table->index(['komisi_periode_id', 'user_id']);
        });

        // Izin: kelola aturan & hitung rekap → manajer; persetujuan (kunci) → hanya administrator kecuali diberikan lewat menu Peran.
        if ($peranId = DB::table('perans')->where('kode', 'manajer')->value('id')) {
            DB::table('peran_izins')->insertOrIgnore(['peran_id' => $peranId, 'izin' => 'komisi.kelola']);
        }
    }

    public function down(): void
    {
        DB::table('peran_izins')->whereIn('izin', ['komisi.kelola', 'komisi.setujui'])->delete();
        Schema::dropIfExists('komisi_barises');
        Schema::dropIfExists('komisi_periodes');
        Schema::table('kunjungan_tindakans', fn (Blueprint $table) => $table->dropConstrainedForeignId('asisten_id'));
        Schema::dropIfExists('aturan_komisis');
    }
};
