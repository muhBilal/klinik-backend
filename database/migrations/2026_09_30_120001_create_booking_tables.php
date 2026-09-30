<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking & penjadwalan (PRD BK-01 s.d. BK-03, AN-01, temuan teknis 8.3 #4).
 *
 * `appointments` adalah entitas terpisah dari `kunjungans`: booking bisa bertanggal kapan saja, sedangkan
 * kunjungan tetap "pasien yang hadir hari ini". Check-in mengubah appointment menjadi kunjungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ruang & alat (laser, dental chair). Petugas memakai `users`, bukan tabel ini.
        Schema::create('sumber_dayas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->string('kode', 20);
            $table->string('nama', 100);
            $table->string('tipe', 10)->comment('ruang / alat');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cabang_id', 'kode']);
            $table->index(['cabang_id', 'tipe']);
        });

        // Sumber daya yang boleh dipakai suatu treatment. Kosong = treatment tidak butuh ruang/alat khusus.
        Schema::create('tindakan_sumber_dayas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tindakan_id')->constrained('tindakans')->cascadeOnDelete();
            $table->foreignId('sumber_daya_id')->constrained('sumber_dayas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tindakan_id', 'sumber_daya_id']);
            $table->index('sumber_daya_id');
        });

        // Jadwal praktik rutin mingguan per petugas per cabang (BK-03).
        Schema::create('jadwal_praktiks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('hari')->comment('0=Minggu s.d. 6=Sabtu (Carbon dayOfWeek)');
            $table->time('jam_mulai');
            $table->time('jam_selesai');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['cabang_id', 'user_id', 'hari']);
        });

        // Cuti / libur (tipe `cuti`) dan jadwal tambahan di luar pola mingguan (tipe `tambahan`).
        // `jam_mulai` null pada `cuti` = libur sehari penuh.
        Schema::create('jadwal_pengecualians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('tipe', 10)->comment('cuti / tambahan');
            $table->time('jam_mulai')->nullable();
            $table->time('jam_selesai')->nullable();
            $table->string('keterangan')->nullable();
            $table->timestamps();

            $table->index(['cabang_id', 'user_id', 'tanggal']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->string('no_booking', 20)->unique();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('poli_id')->nullable()->constrained('polis')->nullOnDelete();
            $table->foreignId('petugas_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Dokter / terapis pelaksana');
            $table->dateTime('mulai_at');
            $table->dateTime('selesai_at')->comment('Termasuk buffer sterilisasi (BK-02)');
            $table->string('status', 20)->default('dijadwalkan')->index();
            $table->text('catatan')->nullable();
            // Terisi saat check-in; satu appointment menghasilkan paling banyak satu kunjungan.
            $table->foreignId('kunjungan_id')->nullable()->unique()->constrained('kunjungans')->nullOnDelete();
            $table->timestamp('dikonfirmasi_at')->nullable();
            $table->timestamp('checkin_at')->nullable();
            $table->string('alasan_batal')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cabang_id', 'mulai_at']);
            $table->index(['petugas_id', 'mulai_at']);
        });

        // Treatment yang dibooking; durasi & buffer di-snapshot agar perubahan katalog tidak menggeser jadwal lama.
        Schema::create('appointment_tindakans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('tindakan_id')->constrained('tindakans')->restrictOnDelete();
            $table->unsignedSmallInteger('durasi_menit');
            $table->unsignedSmallInteger('buffer_menit');
            $table->timestamps();

            $table->unique(['appointment_id', 'tindakan_id']);
        });

        Schema::create('appointment_sumber_dayas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('sumber_daya_id')->constrained('sumber_dayas')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['appointment_id', 'sumber_daya_id']);
            $table->index('sumber_daya_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_sumber_dayas');
        Schema::dropIfExists('appointment_tindakans');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('jadwal_pengecualians');
        Schema::dropIfExists('jadwal_praktiks');
        Schema::dropIfExists('tindakan_sumber_dayas');
        Schema::dropIfExists('sumber_dayas');
    }
};
