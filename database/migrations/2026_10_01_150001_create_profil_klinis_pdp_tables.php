<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data klinis pasien terstruktur (PRD PS-03) & consent UU PDP (PS-04) — PRD v2 #5.
 *
 * - `profil_klinis`: satu baris per pasien (Fitzpatrick, status kehamilan, riwayat obat/penyakit). Terpisah dari `pasiens` agar
 *   tidak ikut terkirim ke peran non-klinis yang hanya memegang `pasien.lihat`.
 * - `pasien_alergis`: alergi terstruktur (zat/obat, reaksi, keparahan); obat_id dipakai peringatan saat meresepkan (FR-02).
 * - `persetujuan_datas`: consent pemrosesan data & opt-in marketing, terpisah, bertanda tangan, di-snapshot, tidak pernah dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profil_klinis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasien_id')->unique()->constrained('pasiens')->restrictOnDelete();
            $table->unsignedTinyInteger('fitzpatrick')->nullable()->comment('Tipe kulit Fitzpatrick 1–6');
            $table->string('status_kehamilan', 15)->nullable()->comment('tidak / hamil / menyusui');
            $table->date('hpht')->nullable()->comment('Hari pertama haid terakhir bila hamil');
            $table->timestamp('status_kehamilan_at')->nullable();
            $table->text('riwayat_obat')->nullable();
            $table->text('riwayat_penyakit')->nullable();
            $table->foreignId('diperbarui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pasien_alergis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->string('jenis', 15)->comment('obat / makanan / lingkungan / lainnya');
            $table->string('zat', 150);
            $table->foreignId('obat_id')->nullable()->constrained('obats')->nullOnDelete();
            $table->string('reaksi')->nullable();
            $table->string('keparahan', 10)->comment('ringan / sedang / berat');
            $table->string('catatan')->nullable();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['pasien_id', 'deleted_at']);
        });

        Schema::create('persetujuan_datas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete();
            $table->string('jenis', 15)->comment('pemrosesan / marketing');
            $table->boolean('setuju');
            $table->text('isi');
            $table->string('status', 10)->comment('berlaku / diganti / dicabut');
            $table->string('penandatangan_nama', 150);
            $table->string('hubungan', 20);
            $table->longText('ttd');
            $table->string('checksum', 64);
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ditandatangani_at');
            $table->timestamp('berakhir_at')->nullable();
            $table->foreignId('dicabut_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_cabut')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['pasien_id', 'jenis', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persetujuan_datas');
        Schema::dropIfExists('pasien_alergis');
        Schema::dropIfExists('profil_klinis');
    }
};
